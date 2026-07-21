<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Contracts\SessionActivityResolver;
use ReneRoscher\UserSessions\Facades\UserSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * The registry's last_activity is debounced by design (one write per sync_interval), so
 * it is stale for display and keeps listing sessions the store already dropped. These
 * tests pin the read-path fix: the store itself is asked for the truth.
 */
beforeEach(function (): void {
    $this->user = User::create(['email' => 'activity@example.com', 'password' => 'password']);
    $this->withoutDefer();
});

function usesRedisSession(): void
{
    config([
        'session.driver' => 'redis',
        'session.lifetime' => 120,
        'cache.default' => 'redis',
        'database.redis.client' => 'phpredis',
        'database.redis.default' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 14,
        ],
    ]);

    Session::forgetDrivers();
}

function redisReachable(): bool
{
    if (! class_exists(Redis::class)) {
        return false;
    }

    try {
        $client = new Redis;
        $client->connect('127.0.0.1', 6379, 0.2);
        $client->ping();
        $client->close();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('reports the array store as not introspectable and falls back to the column', function (): void {
    // Explicitly pin the driver: the suite also runs under SESSION_DRIVER=file|redis,
    // where the resolver IS supported and this assertion would be meaningless.
    config(['session.driver' => 'array']);
    Session::forgetDrivers();

    // No TTL introspection, so the persisted column must remain authoritative rather
    // than every session suddenly looking dead.
    expect(app(SessionActivityResolver::class)->supported())->toBeFalse();

    $session = UserSession::factory()->create([
        'session_id' => 'array-session',
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'last_activity' => now()->subMinutes(5),
    ]);

    UserSessions::resolveActivity([$session]);

    expect($session->existsInStore())->toBeNull()
        ->and($session->lastActivityAt()->timestamp)->toBe($session->last_activity->timestamp)
        ->and($session->isActive())->toBeTrue();
});

describe('redis session store', function (): void {
    beforeEach(function (): void {
        usesRedisSession();

        $this->redisKey = function (string $sessionId): string {
            $store = Session::getHandler()->getCache()->getStore();

            return $store->getPrefix().$sessionId;
        };

        $this->connection = fn () => Session::getHandler()->getCache()->getStore()->connection();
    });

    afterEach(function (): void {
        try {
            ($this->connection)()->flushdb();
        } catch (Throwable) {
            // nothing to clean up when redis was unreachable
        }
    });

    it('resolves last activity from the remaining TTL', function (): void {
        Session::getHandler()->write('redis-fresh', 'payload');

        $resolved = app(SessionActivityResolver::class)->resolve(['redis-fresh']);

        expect(app(SessionActivityResolver::class)->supported())->toBeTrue()
            ->and($resolved)->toHaveKey('redis-fresh')
            // Written just now, so the full lifetime remains and elapsed time is ~0.
            ->and($resolved['redis-fresh']->diffInSeconds(now(), absolute: true))->toBeLessThan(5);
    });

    it('derives how long ago a session was last touched', function (): void {
        Session::getHandler()->write('redis-idle', 'payload');

        // Lifetime is 120 min; leaving 60 min of TTL means the last write was 60 min ago.
        ($this->connection)()->expire(($this->redisKey)('redis-idle'), 60 * 60);

        $resolved = app(SessionActivityResolver::class)->resolve(['redis-idle']);

        expect($resolved['redis-idle']->diffInMinutes(now(), absolute: true))
            ->toBeGreaterThan(59)
            ->toBeLessThan(61);
    });

    it('prefers the store over a stale registry column', function (): void {
        Session::getHandler()->write('redis-stale-row', 'payload');

        // The row says 3 minutes ago (debounce lag); the store knows it was just now.
        $session = UserSession::factory()->create([
            'session_id' => 'redis-stale-row',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now()->subMinutes(3),
        ]);

        UserSessions::resolveActivity([$session]);

        expect($session->lastActivityAt()->diffInSeconds(now(), absolute: true))->toBeLessThan(5)
            ->and($session->existsInStore())->toBeTrue()
            ->and($session->last_activity->diffInMinutes(now(), absolute: true))->toBeGreaterThanOrEqual(3);
    });

    it('marks a session the store no longer holds as inactive', function (): void {
        // Never written to the store: expired, or the id was regenerated mid-session.
        $session = UserSession::factory()->create([
            'session_id' => 'redis-gone',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now(),
        ]);

        expect($session->isActive())->toBeTrue();

        UserSessions::resolveActivity([$session]);

        expect($session->existsInStore())->toBeFalse()
            ->and($session->isActive())->toBeFalse()
            // Still shows "last seen" for the UI even though it is dead.
            ->and($session->lastActivityAt())->not->toBeNull();
    });

    it('annotates and re-sorts the device list by real activity', function (): void {
        Session::getHandler()->write('redis-old', 'payload');
        Session::getHandler()->write('redis-new', 'payload');

        // Row order says "old" is the most recent; the store says the opposite.
        UserSession::factory()->create([
            'session_id' => 'redis-old',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now(),
        ]);
        UserSession::factory()->create([
            'session_id' => 'redis-new',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now()->subMinutes(10),
        ]);

        ($this->connection)()->expire(($this->redisKey)('redis-old'), 30 * 60);

        $sessions = UserSessions::for($this->user);

        expect($sessions->first()->session_id)->toBe('redis-new')
            ->and($sessions->last()->session_id)->toBe('redis-old');
    });

    it('reconciles rows whose sessions the store has dropped', function (): void {
        Session::getHandler()->write('redis-alive', 'payload');

        foreach (['redis-alive', 'redis-dead'] as $id) {
            UserSession::factory()->create([
                'session_id' => $id,
                'user_type' => User::class,
                'user_id' => $this->user->id,
                'last_activity' => now(),
            ]);
        }

        expect(UserSessions::reconcile($this->user))->toBe(1)
            ->and(UserSession::where('session_id', 'redis-dead')->firstOrFail()->revoked_at)->not->toBeNull()
            ->and(UserSession::where('session_id', 'redis-alive')->firstOrFail()->revoked_at)->toBeNull();
    });

    it('never reports the caller\'s own session as dead', function (): void {
        // StartSession only writes the session once the response is finished, so during
        // the very first request of a new session the store legitimately knows nothing
        // about it. Trusting the store blindly would show the user their own device as
        // logged out — and reconcile() would then revoke the session they are using.
        Session::start();

        $session = UserSession::factory()->create([
            'session_id' => Session::getId(),
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now(),
        ]);

        expect(Session::getHandler()->read(Session::getId()))->toBe('');

        UserSessions::resolveActivity([$session]);

        expect($session->existsInStore())->toBeTrue()
            ->and($session->isActive())->toBeTrue()
            ->and(UserSessions::reconcile($this->user))->toBe(0);
    });

    it('falls back to the column when store resolution is disabled', function (): void {
        config(['user-sessions.resolve_activity_from_store' => false]);

        $session = UserSession::factory()->create([
            'session_id' => 'redis-disabled',
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'last_activity' => now()->subMinutes(7),
        ]);

        UserSessions::resolveActivity([$session]);

        expect($session->existsInStore())->toBeNull()
            ->and($session->lastActivityAt()->timestamp)->toBe($session->last_activity->timestamp);
    });
})->skip(fn (): bool => ! redisReachable(), 'redis server not reachable on 127.0.0.1:6379');

describe('file session store', function (): void {
    beforeEach(function (): void {
        $this->sessionPath = sys_get_temp_dir().'/user-sessions-test-'.bin2hex(random_bytes(4));

        mkdir($this->sessionPath);

        config([
            'session.driver' => 'file',
            'session.files' => $this->sessionPath,
            'session.lifetime' => 120,
        ]);

        Session::forgetDrivers();
    });

    afterEach(function (): void {
        array_map(unlink(...), glob($this->sessionPath.'/*') ?: []);
        rmdir($this->sessionPath);
    });

    it('resolves last activity from the session file mtime', function (): void {
        Session::getHandler()->write('file-session', 'payload');

        touch($this->sessionPath.'/file-session', now()->subMinutes(20)->getTimestamp());

        $resolved = app(SessionActivityResolver::class)->resolve(['file-session']);

        expect(app(SessionActivityResolver::class)->supported())->toBeTrue()
            ->and($resolved['file-session']->diffInMinutes(now(), absolute: true))
            ->toBeGreaterThan(19)
            ->toBeLessThan(21);
    });

    it('reports a missing session file as gone', function (): void {
        expect(app(SessionActivityResolver::class)->resolve(['file-missing']))
            ->toBe(['file-missing' => null]);
    });

    it('refuses to walk out of the session directory', function (): void {
        // Session ids come from our own table, but a traversal must never be possible.
        expect(app(SessionActivityResolver::class)->resolve(['../../../etc/passwd']))->toBe([]);
    });
});
