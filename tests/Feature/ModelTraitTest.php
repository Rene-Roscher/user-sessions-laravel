<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Events\UserSessionRevoked;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\RevokedBy;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();
});

it('currentSession returns the model for the active request session', function (): void {
    $request = request();
    $request->setLaravelSession(session()->driver());
    $id = $request->session()->getId();

    UserSession::factory()->create([
        'session_id' => $id,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect($this->user->currentSession($request)?->session_id)->toBe($id);
});

it('currentSession returns null without a session', function (): void {
    $request = Request::create('/');

    expect($this->user->currentSession($request))->toBeNull();
});

it('isCurrent reflects whether the session matches the request', function (): void {
    $request = request();
    $request->setLaravelSession(session()->driver());
    $id = $request->session()->getId();

    $current = UserSession::factory()->make(['session_id' => $id]);
    $other = UserSession::factory()->make(['session_id' => 'somewhere-else']);

    expect($current->isCurrent($request))->toBeTrue()
        ->and($other->isCurrent($request))->toBeFalse();
});

// New DX surface — the three trait methods a consumer controller needs.

it('revokeSession revokes one of the user\'s own sessions by registry id', function (): void {
    Event::fake([UserSessionRevoked::class]);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'session_id' => 'to-revoke',
    ]);

    expect($this->user->revokeSession($session->id))->toBeTrue();

    $session->refresh();

    expect($session->revoked_at)->not->toBeNull();

    Event::assertDispatched(UserSessionRevoked::class, fn (UserSessionRevoked $e): bool => $e->revokedBy === RevokedBy::SELF);
});

it('revokeSession returns false for a foreign session id', function (): void {
    $other = User::create(['email' => 'other@example.com', 'password' => 'password']);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $other->id,
        'session_id' => 'foreign-session',
    ]);

    // Scoped to the caller's own rows: a foreign id is a miss, not a cross-account revoke.
    expect($this->user->revokeSession($session->id))->toBeFalse()
        ->and(UserSession::where('session_id', 'foreign-session')->first()->revoked_at)->toBeNull();
});

it('revokeSession honours a custom revokedBy reason', function (): void {
    Event::fake([UserSessionRevoked::class]);

    $session = UserSession::factory()->create([
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $this->user->revokeSession($session->id, RevokedBy::ADMIN);

    Event::assertDispatched(UserSessionRevoked::class, fn (UserSessionRevoked $e): bool => $e->revokedBy === RevokedBy::ADMIN);
});

it('lastActiveHuman returns a human-readable relative time for the last activity', function (): void {
    $session = UserSession::factory()->make([
        'last_activity' => now()->subMinutes(5),
    ]);

    expect($session->lastActiveHuman())->toBe('5 minutes ago');
});

it('lastActiveHuman returns null when no last activity is known', function (): void {
    $session = UserSession::factory()->make(['last_activity' => null]);

    expect($session->lastActiveHuman())->toBeNull();
});

it('activeSessions drops store-dead rows from the list without the consumer calling reconcile()', function (): void {
    // Use the file driver so the store is introspectable: the dead row's existsInStore()
    // resolves to false, isActive() returns false, and it drops from the list.
    $sessionPath = sys_get_temp_dir().'/user-sessions-trait-'.bin2hex(random_bytes(4));
    mkdir($sessionPath);
    config(['session.driver' => 'file', 'session.files' => $sessionPath]);
    Session::forgetDrivers();

    try {
        Session::getHandler()->write('alive-trait', 'payload');

        UserSession::factory()->create([
            'session_id' => 'alive-trait',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now(),
        ]);

        UserSession::factory()->create([
            'session_id' => 'dead-trait',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now(),
        ]);

        $sessions = $this->user->activeSessions();

        expect($sessions)->toHaveCount(1)
            ->and($sessions->first()->session_id)->toBe('alive-trait');
    } finally {
        array_map(unlink(...), glob($sessionPath.'/*') ?: []);
        @rmdir($sessionPath);
    }
});

it('activeSessions stays correct when the store cannot be introspected', function (): void {
    // array driver: supported() is false, reconcile() is a no-op, resolveActivity() falls
    // back to the registry column. The list must still work — just column-truth, not
    // store-truth.
    config(['session.driver' => 'array']);
    Session::forgetDrivers();

    UserSession::factory()->create([
        'session_id' => 'array-trait',
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'last_activity' => now(),
    ]);

    expect($this->user->activeSessions())->toHaveCount(1);
});
