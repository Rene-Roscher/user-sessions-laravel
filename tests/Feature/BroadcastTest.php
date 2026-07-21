<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Events\UserSessionRevoked;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();
});

it('broadcasts on a morph-scoped private channel when broadcasting is enabled', function (): void {
    config(['user-sessions.broadcast' => true]);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => 42,
    ]);

    $event = new UserSessionRevoked($session, 'admin');

    $channels = $event->broadcastOn();

    // The morph type is part of the channel so two models sharing an integer id never collide.
    $expected = 'private-user-sessions.'.str_replace('\\', '.', User::class).'.42';

    expect($event->broadcastWhen())->toBeTrue()
        ->and($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe($expected);
});

it('scopes the broadcast channel by morph type so ids cannot collide across models', function (): void {
    config(['user-sessions.broadcast' => true]);

    $userSession = UserSession::factory()->create(['user_type' => User::class, 'user_id' => 1]);
    $adminSession = UserSession::factory()->create(['user_type' => 'App\\Models\\Admin', 'user_id' => 1]);

    $userChannel = (new UserSessionCreated($userSession, true))->broadcastOn()[0]->name;
    $adminChannel = (new UserSessionCreated($adminSession, true))->broadcastOn()[0]->name;

    expect($userChannel)->not->toBe($adminChannel);
});

it('does not attempt to broadcast when broadcasting is disabled', function (): void {
    config(['user-sessions.broadcast' => false]);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $event = new UserSessionCreated($session, isNewDevice: true);

    expect($event->broadcastWhen())->toBeFalse()
        ->and($event->broadcastOn())->toBe([]);
});

it('exposes a stable broadcast name and payload for revoked sessions', function (): void {
    $session = UserSession::factory()->create([
        'session_id' => 'sid-1',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $event = new UserSessionRevoked($session, 'admin');

    expect($event->broadcastAs())->toBe('session.revoked')
        ->and($event->broadcastWith())->toMatchArray([
            'id' => $session->getKey(),
            'revoked_by' => 'admin',
        ]);
});

it('never broadcasts the raw session id', function (): void {
    $session = UserSession::factory()->create([
        'session_id' => 'sid-secret',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    // The payload reaches the browser and any third-party broadcaster, so it must not
    // carry a bearer credential — least of all a sibling device's session id.
    expect((new UserSessionRevoked($session, 'admin'))->broadcastWith())->not->toHaveKey('session_id')
        ->and((new UserSessionCreated($session, isNewDevice: true))->broadcastWith())->not->toHaveKey('session_id');
});

it('exposes a stable broadcast name and payload for created sessions', function (): void {
    $session = UserSession::factory()->create([
        'session_id' => 'sid-2',
        'browser' => 'Chrome',
        'platform' => 'macOS',
        'device_type' => 'desktop',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $event = new UserSessionCreated($session, isNewDevice: true);

    expect($event->broadcastAs())->toBe('session.created')
        ->and($event->broadcastWith())->toMatchArray([
            'id' => $session->getKey(),
            'is_new_device' => true,
            'device' => 'Chrome on macOS',
        ]);
});
