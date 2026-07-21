<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();

    $this->makeRequest = function (?User $user): Request {
        $request = Request::create('/');
        $request->setLaravelSession(session()->driver());

        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    };
});

it('resolves the current session model via the macro', function (): void {
    $request = ($this->makeRequest)($this->user);
    $id = $request->session()->getId();

    UserSession::factory()->create([
        'session_id' => $id,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $result = $request->userSession();

    expect($result)->not->toBeNull()
        ->and($result->session_id)->toBe($id);
});

it('returns null for a guest request', function (): void {
    $request = ($this->makeRequest)(null);

    expect($request->userSession())->toBeNull();
});

it('memoizes the model so a second call issues no extra query', function (): void {
    $request = ($this->makeRequest)($this->user);
    $id = $request->session()->getId();

    UserSession::factory()->create([
        'session_id' => $id,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'user_sessions')) {
            $queries++;
        }
    });

    $first = $request->userSession();
    $second = $request->userSession();

    expect($queries)->toBe(1)
        ->and($second)->toBe($first);
});

it('returns null for a memoized lookup before the session is loaded, without querying', function (): void {
    $request = ($this->makeRequest)($this->user);
    $id = $request->session()->getId();

    UserSession::factory()->create([
        'session_id' => $id,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'user_sessions')) {
            $queries++;
        }
    });

    $result = $request->userSession(memoized: true);

    expect($result)->toBeNull()
        ->and($queries)->toBe(0);
});

it('returns the cached model for a memoized lookup after it has been loaded', function (): void {
    $request = ($this->makeRequest)($this->user);
    $id = $request->session()->getId();

    UserSession::factory()->create([
        'session_id' => $id,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $loaded = $request->userSession();
    $memoized = $request->userSession(memoized: true);

    expect($memoized)->not->toBeNull()
        ->and($memoized)->toBe($loaded);
});
