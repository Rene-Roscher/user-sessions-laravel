<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Facades\UserSessions as UserSessionsFacade;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
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

// §12 Test 4b — revoking your own current session must invalidate (flush + regenerate)
// so StartSession cannot write the in-memory payload back and resurrect it.
it('invalidates the current session on self-revoke to prevent resurrection', function (): void {
    session()->start();
    $sessionId = session()->getId();

    $session = UserSession::factory()->create([
        'session_id' => $sessionId,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    UserSessionsFacade::revoke($session);

    expect($session->fresh()->revoked_at)->not->toBeNull()
        ->and(session()->getId())->not->toBe($sessionId);
});

// §12 Test 4c — self-revoke from a queue/CLI context (no active session) must not throw
// and must not attempt session invalidation; the row is still marked revoked.
it('revokes cleanly without an active session', function (): void {
    $session = UserSession::factory()->create([
        'session_id' => 'cli-session',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $result = UserSessionsFacade::revoke($session);

    expect($result)->toBeTrue()
        ->and($session->fresh()->revoked_at)->not->toBeNull();
});

// Audit regression: revoking the current session in-request regenerates the session ID, but
// the guard still holds the user; the middleware must NOT then record the regenerated (now
// guest) session as a fresh active row for the just-revoked user.
it('does not re-record the regenerated session after an in-request self-revoke', function (): void {
    $userId = $this->user->id;

    app('router')->get('/self-revoke', function () use ($userId) {
        $sessionId = session()->getId();

        UserSession::factory()->create([
            'session_id' => $sessionId,
            'user_type' => User::class,
            'user_id' => $userId,
        ]);

        $current = UserSession::query()->where('session_id', $sessionId)->first();
        app(ManagesUserSessions::class)->revoke($current);

        return response('OK');
    })->middleware(['web', StartSession::class, RecordSessions::class]);

    // Real deferred lifecycle so the middleware's post-response record() actually runs.
    $this->actingAs($this->user)->get('/self-revoke')->assertOk();

    // The revoked row stays revoked and NO new active row was created for the regenerated id.
    expect(UserSession::query()->whereNull('revoked_at')->count())->toBe(0);
});
