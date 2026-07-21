<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Exceptions\CouldNotRevokeSessions;
use ReneRoscher\UserSessions\Facades\UserSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * Regressions for the defects an adversarial review found in the hardening pass itself.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->user = User::create(['email' => 'review@example.com', 'password' => 'password']);

    $this->sessionDir = function (): string {
        $path = sys_get_temp_dir().'/user-sessions-review-'.bin2hex(random_bytes(4));
        mkdir($path);
        $this->createdDirs[] = $path;

        return $path;
    };
    $this->createdDirs = [];
});

afterEach(function (): void {
    foreach ($this->createdDirs ?? [] as $path) {
        array_map(unlink(...), glob($path.'/*') ?: []);
        @rmdir($path);
    }
});

it('still announces a new device after the first write failed', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    // Simulate the write failing (deadlock, connection blip, failover). record() runs
    // inside defer(), whose exceptions Laravel rescues, so this is invisible to the user.
    //
    // Point the recorder at a missing table rather than renaming the real one: DDL is not
    // transactional on MySQL (it would implicitly commit RefreshDatabase's transaction),
    // and the nested transaction makes this a SAVEPOINT so Postgres — which aborts an
    // entire transaction after any failed statement — stays usable afterwards.
    config(['user-sessions.table' => 'table_that_does_not_exist']);

    try {
        DB::transaction(function (): void {
            app(SessionRecorder::class)->record('flaky', $this->user, '127.0.0.1', 'Mozilla/5.0');
        });
    } catch (Throwable) {
        // expected
    }

    config(['user-sessions.table' => 'user_sessions']);

    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('flaky', $this->user, '127.0.0.1', 'Mozilla/5.0');

    // Claiming the "announced" marker before the write used to burn it on the failed
    // attempt, permanently swallowing the security alert for that login.
    Event::assertDispatched(UserSessionCreated::class);

    expect(UserSession::where('session_id', 'flaky')->exists())->toBeTrue();
});

it('announces a session only once when writes succeed', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('once', $this->user, '127.0.0.1', 'Mozilla/5.0');
    app(SessionRecorder::class)->record('once', $this->user, '127.0.0.1', 'Mozilla/5.0');

    Event::assertDispatchedTimes(UserSessionCreated::class, 1);
});

it('records a session whose user agent is not valid UTF-8', function (): void {
    // Arbitrary client bytes: MySQL rejects these with "Incorrect string value" and
    // Postgres with "invalid byte sequence", aborting the upsert just like an over-long
    // value would — and just as permanently, since the debounce marker is already claimed.
    app(SessionRecorder::class)->record('binary-ua', $this->user, '127.0.0.1', "Mozilla/5.0 \xC3\x28 \xFF\xFE");

    $stored = UserSession::where('session_id', 'binary-ua')->firstOrFail();

    expect(mb_check_encoding((string) $stored->user_agent, 'UTF-8'))->toBeTrue();
});

it('strips control characters from the stored user agent', function (): void {
    app(SessionRecorder::class)->record('ctrl-ua', $this->user, '127.0.0.1', "Mozilla/5.0\r\nX-Injected: 1");

    expect(UserSession::where('session_id', 'ctrl-ua')->value('user_agent'))
        ->not->toContain("\r")
        ->not->toContain("\n");
});

it('revokes every other session even when one row fails', function (): void {
    foreach (['bulk-a', 'bulk-b', 'bulk-c'] as $id) {
        Session::getHandler()->write($id, 'payload');

        UserSession::factory()->create([
            'session_id' => $id,
            'user_type' => User::class,
            'user_id' => $this->user->id,
        ]);
    }

    // Make the bookkeeping write fail for one row only.
    UserSession::saving(fn (UserSession $session): bool => $session->session_id !== 'bulk-b');

    try {
        UserSessions::revokeAll($this->user);

        // Partial failure must not look like success.
        expect(false)->toBeTrue('revokeAll should have reported the failure');
    } catch (CouldNotRevokeSessions $e) {
        expect($e->failures)->toHaveKey('bulk-b')
            ->and($e->revoked)->toBe(2);
    } finally {
        UserSession::flushEventListeners();
    }

    // The loop must not have aborted on the first failure: the other two are really gone
    // from the store, not left authenticated behind a success message.
    expect(Session::getHandler()->read('bulk-a'))->toBe('')
        ->and(Session::getHandler()->read('bulk-c'))->toBe('');
});

it('does not list a session the store has already dropped', function (): void {
    config(['session.driver' => 'file', 'session.files' => ($this->sessionDir)()]);
    Session::forgetDrivers();

    storeSession('alive-row');

    // 'dead-row' deliberately gets no store entry: an expired session, or an id that was
    // regenerated mid-flight, which the registry has not caught up with yet.
    foreach (['alive-row', 'dead-row'] as $id) {
        UserSession::factory()->create([
            'session_id' => $id,
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now(),
        ]);
    }

    $sessions = UserSessions::for($this->user);

    // Returning it would render a logged-out device as live, with a working "log out"
    // button and a recent "last active" read straight off the stale debounced column.
    expect($sessions->pluck('session_id')->all())->toBe(['alive-row']);
});

it('does not list a session that has outlived the session lifetime', function (): void {
    UserSession::factory()->expired()->create([
        'session_id' => 'expired-row',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect(UserSessions::for($this->user))->toBeEmpty();
});

it('honours a renamed registry table across every Eloquent path', function (): void {
    Schema::rename('user_sessions', 'session_registry');
    config(['user-sessions.table' => 'session_registry']);

    storeSession('renamed');
    app(SessionRecorder::class)->record('renamed', $this->user, '127.0.0.1', 'Mozilla/5.0');

    // The recorder always honoured the config; the model derived the name from its class,
    // so the facade, the relation, the macro and model:prune all queried a missing table.
    expect(UserSessions::find('renamed'))->not->toBeNull()
        ->and($this->user->sessions()->count())->toBe(1)
        ->and(UserSessions::for($this->user))->toHaveCount(1);

    Schema::rename('session_registry', 'user_sessions');
});
