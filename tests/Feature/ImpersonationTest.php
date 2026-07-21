<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\ImpersonatedUser;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();

    // A route that runs the middleware exactly as the 'web' group would.
    app('router')->get('/imp', fn () => response('OK'))
        ->middleware(['web', StartSession::class, RecordSessions::class]);
});

// An admin impersonating a user must NOT create a registry row, fire events, or send
// a "new device" notification to that user.
it('does not track when an impersonation session key is present', function (): void {
    config(['user-sessions.impersonation.session_keys' => ['impersonated_by']]);

    Event::fake([UserSessionCreated::class]);

    $this->withSession(['impersonated_by' => 999])
        ->actingAs($this->user)
        ->get('/imp')
        ->assertOk();

    // No row and no created event → the new-device notification can never be produced.
    $this->assertDatabaseEmpty('user_sessions');
    Event::assertNotDispatched(UserSessionCreated::class);
});

it('does not track when the user model reports it is impersonated', function (): void {
    $impersonated = ImpersonatedUser::create([
        'email' => 'victim@example.com',
        'password' => 'password',
    ]);

    Event::fake([UserSessionCreated::class]);

    $this->actingAs($impersonated)->get('/imp')->assertOk();

    $this->assertDatabaseEmpty('user_sessions');
    Event::assertNotDispatched(UserSessionCreated::class);
});

it('tracks normally once impersonation has ended', function (): void {
    $this->actingAs($this->user)->get('/imp')->assertOk();

    $this->assertDatabaseHas('user_sessions', [
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
});

it('still records directly via the recorder (impersonation is a middleware-level concern)', function (): void {
    // The recorder itself is unconditional; impersonation is filtered before it is called.
    app(SessionRecorder::class)->record('direct', $this->user, '1.1.1.1', 'Chrome');

    $this->assertDatabaseHas('user_sessions', ['session_id' => 'direct']);
});
