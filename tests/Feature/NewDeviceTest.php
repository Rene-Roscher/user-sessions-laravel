<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();
});

it('reports isNewDevice=true for the first login from a device', function (): void {
    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('sess-1', $this->user, '1.1.1.1', CHROME_MAC);

    Event::assertDispatched(fn (UserSessionCreated $e): bool => $e->isNewDevice === true);
});

it('reports isNewDevice=false when the same device is already active', function (): void {
    UserSession::factory()->create([
        'session_id' => 'existing',
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'device_type' => 'desktop',
        'platform' => 'macOS',
        'browser' => 'Chrome',
        'last_activity' => now(),
        'revoked_at' => null,
    ]);

    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('sess-2', $this->user, '1.1.1.1', CHROME_MAC);

    Event::assertDispatched(fn (UserSessionCreated $e): bool => $e->session->session_id === 'sess-2' && $e->isNewDevice === false);
});

it('reports isNewDevice=true again once the known-device window has passed', function (): void {
    $window = (int) config('user-sessions.new_device_window', 86400);

    UserSession::factory()->create([
        'session_id' => 'stale',
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'device_type' => 'desktop',
        'platform' => 'macOS',
        'browser' => 'Chrome',
        'last_activity' => now()->subSeconds($window + 60),
        'revoked_at' => null,
    ]);

    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('fresh', $this->user, '1.1.1.1', CHROME_MAC);

    Event::assertDispatched(fn (UserSessionCreated $e): bool => $e->session->session_id === 'fresh' && $e->isNewDevice === true);
});

it('ignores a revoked session of the same device when deciding newness', function (): void {
    UserSession::factory()->create([
        'session_id' => 'revoked-same',
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'device_type' => 'desktop',
        'platform' => 'macOS',
        'browser' => 'Chrome',
        'last_activity' => now(),
        'revoked_at' => now(),
    ]);

    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('fresh', $this->user, '1.1.1.1', CHROME_MAC);

    Event::assertDispatched(fn (UserSessionCreated $e): bool => $e->isNewDevice === true);
});
