<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * The whole point of the package: authenticated traffic must not write to the database
 * on every request. Guest traffic is covered by GuestFloodTest; this covers the far more
 * dangerous case, because an authenticated flood DOES go through the recorder.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->user = User::create(['email' => 'hot@example.com', 'password' => 'password']);

    // Laravel's test client does not carry the session cookie between calls, so every
    // $this->get() would otherwise start a brand-new session — which would silently make
    // a debounce test pass for the wrong reason (nothing to debounce). Pin one session
    // id across the burst, exactly like a real browser does.
    $this->sessionId = Str::random(40);
    $this->withCookie(config('session.cookie'), $this->sessionId);

    $this->countWritesToRegistry = function (callable $body): int {
        $writes = 0;

        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'user_sessions')
                && (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update'))) {
                $writes++;
            }
        });

        $body();

        return $writes;
    };
});

it('writes to the registry once across a burst of authenticated requests', function (): void {
    config(['user-sessions.sync_interval' => 180]);

    $this->actingAs($this->user);

    $writes = ($this->countWritesToRegistry)(function (): void {
        for ($i = 0; $i < 25; $i++) {
            $this->get('/protected')->assertOk();
        }
    });

    // 25 requests, one write. Anything else means the debounce is not holding and the
    // package is costing more than the database session driver it replaces.
    expect($writes)->toBe(1);

    $this->assertDatabaseCount('user_sessions', 1);
});

it('writes again once the debounce interval has elapsed', function (): void {
    // Pin the markers to an in-process store: Carbon time travel moves PHP's clock, not
    // a Redis server's TTL, so on the redis leg of the driver matrix this would otherwise
    // assert nothing. The expiry logic under test is the package's, not the store's.
    config([
        'user-sessions.sync_interval' => 180,
        'cache.stores.debounce_probe' => ['driver' => 'array'],
        'user-sessions.cache_store' => 'debounce_probe',
    ]);

    $this->actingAs($this->user);

    $this->get('/protected')->assertOk();

    // Real expiry rather than forgetting the key: proves the TTL itself is what gates
    // the next write.
    $this->travel(181)->seconds();

    $writes = ($this->countWritesToRegistry)(function (): void {
        $this->get('/protected')->assertOk();
        $this->get('/protected')->assertOk();
    });

    expect($writes)->toBe(1);

    $this->travelBack();
});

it('still keeps exactly one row while syncing every request', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    $this->actingAs($this->user);

    $writes = ($this->countWritesToRegistry)(function (): void {
        for ($i = 0; $i < 5; $i++) {
            $this->get('/protected')->assertOk();
        }
    });

    expect($writes)->toBe(5)
        ->and(DB::table('user_sessions')->count())->toBe(1);
});

it('does not fall back to a database read for the debounce decision', function (): void {
    config(['user-sessions.sync_interval' => 180]);

    $this->actingAs($this->user);

    $this->get('/protected')->assertOk();

    $selects = 0;

    DB::listen(function ($query) use (&$selects): void {
        if (str_contains(strtolower($query->sql), 'user_sessions') && str_starts_with(strtolower($query->sql), 'select')) {
            $selects++;
        }
    });

    for ($i = 0; $i < 10; $i++) {
        $this->get('/protected')->assertOk();
    }

    // The debounce marker lives in the cache precisely so the hot path never reads the
    // registry back — a select here would reintroduce the cost through the back door.
    expect($selects)->toBe(0);
});

it('uses the configured cache store for its markers', function (): void {
    config([
        'user-sessions.sync_interval' => 180,
        'cache.stores.markers' => ['driver' => 'array'],
        'user-sessions.cache_store' => 'markers',
    ]);

    $this->actingAs($this->user);

    $writes = ($this->countWritesToRegistry)(function (): void {
        $this->get('/protected')->assertOk();
        $this->get('/protected')->assertOk();
    });

    expect($writes)->toBe(1)
        ->and(Cache::store('markers')->get('user-sessions:debounce:'.$this->sessionId))->not->toBeNull();
});
