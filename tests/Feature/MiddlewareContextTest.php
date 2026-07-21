<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

const MW_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    Context::flush();
    $this->withoutDefer();

    $mw = ['web', StartSession::class, RecordSessions::class];
    app('router')->get('/ctx-mw', fn () => response('OK'))->middleware($mw);
});

// Audit regression: the middleware used to gate context sharing on App::runningInConsole(),
// which is TRUE under Octane/Swoole (and in tests), so context was silently never shared.
// Driving the real middleware must attach the device context.
it('shares the log context through the middleware regardless of console SAPI', function (): void {
    $this->actingAs($this->user)
        ->withHeaders(['User-Agent' => MW_CHROME])
        ->get('/ctx-mw')
        ->assertOk();

    expect(Context::get('device'))->toBe('Chrome on macOS');
});

it('shares no context for a guest request', function (): void {
    $this->withHeaders(['User-Agent' => MW_CHROME])
        ->get('/ctx-mw')
        ->assertOk();

    expect(Context::get('device'))->toBeNull();
});
