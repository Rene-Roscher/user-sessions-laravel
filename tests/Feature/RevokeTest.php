<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Events\UserSessionRevoked;
use ReneRoscher\UserSessions\Facades\UserSessions as UserSessionsFacade;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\Admin;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();
});

it('revoke marks session as revoked and fires event', function (): void {
    Event::fake([UserSessionRevoked::class]);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $result = UserSessionsFacade::revoke($session);

    expect($result)->toBeTrue();

    $session->refresh();

    expect($session->revoked_at)->not->toBeNull();

    Event::assertDispatched(UserSessionRevoked::class);
});

it('revoke returns false for already revoked session', function (): void {
    $session = UserSession::factory()->revoked()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $result = UserSessionsFacade::revoke($session);

    expect($result)->toBeFalse();
});

it('revoke by session-id string', function (): void {
    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $result = UserSessionsFacade::revoke($session->session_id);

    expect($result)->toBeTrue();

    $session->refresh();

    expect($session->revoked_at)->not->toBeNull();
});

it('revoke returns false for unknown session-id', function (): void {
    $result = UserSessionsFacade::revoke('nonexistent-id');

    expect($result)->toBeFalse();
});

it('revokeOthers revokes all sessions except current', function (): void {
    $current = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'current-session',
    ]);

    $other1 = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'other-session-1',
    ]);

    $other2 = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'other-session-2',
    ]);

    $count = UserSessionsFacade::revokeOthers($this->user, 'current-session');

    expect($count)->toBe(2);

    $current->refresh();
    $other1->refresh();
    $other2->refresh();

    expect($current->revoked_at)->toBeNull()
        ->and($other1->revoked_at)->not->toBeNull()
        ->and($other2->revoked_at)->not->toBeNull();
});

it('revokeAll revokes all sessions including current', function (): void {
    $s1 = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'session-1',
    ]);

    $s2 = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'session-2',
    ]);

    $count = UserSessionsFacade::revokeAll($this->user);

    expect($count)->toBe(2);

    $s1->refresh();
    $s2->refresh();

    expect($s1->revoked_at)->not->toBeNull()
        ->and($s2->revoked_at)->not->toBeNull();
});

it('for returns all active sessions for a user', function (): void {
    // for() is the device list, so it asks the store whether each session is still alive.
    // A faked row with no store entry is a dead session, not a live device.
    foreach (['live-one', 'live-two'] as $id) {
        storeSession($id);

        UserSession::factory()->create([
            'session_id' => $id,
            'user_type' => User::class,
            'user_id' => $this->user->id,
        ]);
    }

    UserSession::factory()->revoked()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $sessions = UserSessionsFacade::for($this->user);

    expect($sessions)->toHaveCount(2);
});

it('find returns session by session_id', function (): void {
    $session = UserSession::factory()->create([
        'session_id' => 'find-me',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $found = UserSessionsFacade::find('find-me');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($session->id);
});

it('find returns null for unknown session_id', function (): void {
    $found = UserSessionsFacade::find('nonexistent');

    expect($found)->toBeNull();
});

it('model revoke method delegates to registry', function (): void {
    Event::fake([UserSessionRevoked::class]);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $result = $session->revoke();

    expect($result)->toBeTrue();

    $session->refresh();

    expect($session->revoked_at)->not->toBeNull();
});

it('revokeOthers does not affect other users sessions', function (): void {
    $otherUser = User::create([
        'email' => 'other@example.com',
        'password' => 'password',
    ]);

    $mySession = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'my-session',
    ]);

    $otherSession = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $otherUser->id,
        'session_id' => 'other-user-session',
    ]);

    UserSessionsFacade::revokeOthers($this->user, 'my-session');

    $otherSession->refresh();

    expect($otherSession->revoked_at)->toBeNull();
});

it('separates sessions by morph type (User vs Admin)', function (): void {
    $admin = Admin::create([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    storeSession('morph-user');
    storeSession('morph-admin');

    $userSession = UserSession::factory()->create([
        'session_id' => 'morph-user',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $adminSession = UserSession::factory()->create([
        'session_id' => 'morph-admin',
        'user_type' => Admin::class,
        'user_id' => $admin->id,
    ]);

    $userSessions = UserSessionsFacade::for($this->user);
    $adminSessions = UserSessionsFacade::for($admin);

    expect($userSessions)->toHaveCount(1)
        ->and($adminSessions)->toHaveCount(1)
        ->and($userSessions->first()->user_type)->toBe(User::class)
        ->and($adminSessions->first()->user_type)->toBe(Admin::class);
});

it('HasUserSessions trait provides sessions relation', function (): void {
    UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    UserSession::factory()->revoked()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect($this->user->sessions)->toHaveCount(2)
        ->and($this->user->sessions()->active()->get())->toHaveCount(1);
});

it('HasUserSessions trait revokeOtherSessions method', function (): void {
    $request = request();
    $request->setLaravelSession(session()->driver());
    $currentId = $request->session()->getId();

    UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => $currentId,
    ]);

    UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'other',
    ]);

    $count = $this->user->revokeOtherSessions($request);

    expect($count)->toBe(1);
});

it('HasUserSessions trait revokeAllSessions method', function (): void {
    UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $count = $this->user->revokeAllSessions();

    expect($count)->toBe(2);
});

it('isActive returns true for non-revoked recent session', function (): void {
    $session = UserSession::factory()->create([
        'last_activity' => now(),
        'revoked_at' => null,
    ]);

    expect($session->isActive())->toBeTrue();
});

it('isActive returns false for revoked session', function (): void {
    $session = UserSession::factory()->revoked()->create();

    expect($session->isActive())->toBeFalse();
});

it('isActive returns false for expired session', function (): void {
    $session = UserSession::factory()->expired()->create();

    expect($session->isActive())->toBeFalse();
});

it('scopes work correctly', function (): void {
    UserSession::factory()->create([
        'last_activity' => now(),
        'revoked_at' => null,
    ]);

    UserSession::factory()->revoked()->create();
    UserSession::factory()->expired()->create();

    expect(UserSession::active()->count())->toBe(1)
        ->and(UserSession::revoked()->count())->toBe(1)
        ->and(UserSession::expired()->count())->toBe(1);
});
