<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
});

it('auto-registers the recording middleware into the configured group', function (): void {
    $webGroup = $this->app->make('router')->getMiddlewareGroups()['web'] ?? [];

    expect($webGroup)->toContain(RecordSessions::class);
});

it('records a session through the web group without explicit package middleware', function (): void {
    $this->withoutDefer();

    $this->actingAs($this->user)->get('/web-home')->assertOk();

    $this->assertDatabaseHas('user_sessions', [
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
});

it('dispatches the created event at boot so a consumer listener can send mail', function (): void {
    Event::fake([UserSessionCreated::class]);
    $this->withoutDefer();

    $this->actingAs($this->user)->get('/web-home')->assertOk();

    Event::assertDispatched(UserSessionCreated::class);
});

it('registers the userSession macro on the request', function (): void {
    expect(Request::hasMacro('userSession'))->toBeTrue();
});

it('does not record or dispatch for an impersonated request', function (): void {
    Event::fake([UserSessionCreated::class]);
    $this->withoutDefer();

    app('router')->get('/imp-boot', fn () => response('OK'))
        ->middleware(['web', StartSession::class, RecordSessions::class]);

    $this->withSession(['impersonated_by' => 999])
        ->actingAs($this->user)
        ->get('/imp-boot')
        ->assertOk();

    $this->assertDatabaseEmpty('user_sessions');
    Event::assertNotDispatched(UserSessionCreated::class);
});
