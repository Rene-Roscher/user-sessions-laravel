<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use ReneRoscher\UserSessions\Context\ShareSessionContext;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\CurrentUserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

const CTX_CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->makeRequest = function (): Request {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => CTX_CHROME_MAC]);
        $request->setLaravelSession(session()->driver());

        return $request;
    };
});

it('shares the parsed device label in the context', function (): void {
    app(ShareSessionContext::class)->share(($this->makeRequest)());

    expect(Context::get('device'))->toBe('Chrome on macOS');
});

it('shares the registry row id, never the raw session id', function (): void {
    $user = User::create(['email' => 'ctx@example.com', 'password' => 'password']);

    $request = ($this->makeRequest)();
    $request->setUserResolver(fn (): User => $user);
    $sessionId = $request->session()->getId();

    $row = UserSession::factory()->create([
        'session_id' => $sessionId,
        'user_type' => User::class,
        'user_id' => $user->id,
    ]);

    // The row has not been resolved in this request yet, so nothing is shared — the
    // context sharer must never spend a query on the hot path.
    app(ShareSessionContext::class)->share($request);

    expect(Context::get('user_session_id'))->toBeNull();

    // Once something has loaded the row, its ULID is shared.
    CurrentUserSession::resolve($request);
    Context::flush();

    app(ShareSessionContext::class)->share($request);

    expect(Context::get('user_session_id'))->toBe($row->getKey())
        ->and(Context::get('user_session_id'))->not->toBe($sessionId);
});

it('never leaks the session id into the context', function (): void {
    $user = User::create(['email' => 'leak@example.com', 'password' => 'password']);

    $request = ($this->makeRequest)();
    $request->setUserResolver(fn (): User => $user);
    $sessionId = $request->session()->getId();

    UserSession::factory()->create([
        'session_id' => $sessionId,
        'user_type' => User::class,
        'user_id' => $user->id,
    ]);

    CurrentUserSession::resolve($request);
    app(ShareSessionContext::class)->share($request);

    // Context is copied into every log line and every queued job payload dispatched
    // from this request — a session id there is a harvestable bearer credential.
    expect(json_encode(Context::all()))->not->toContain($sessionId);
});

it('shares nothing when context sharing is disabled', function (): void {
    config(['user-sessions.context.enabled' => false]);

    app(ShareSessionContext::class)->share(($this->makeRequest)());

    expect(Context::get('device'))->toBeNull()
        ->and(Context::get('user_session_id'))->toBeNull();
});

it('respects the configured context keys', function (): void {
    config(['user-sessions.context.keys' => ['device']]);

    app(ShareSessionContext::class)->share(($this->makeRequest)());

    expect(Context::get('device'))->toBe('Chrome on macOS')
        ->and(Context::get('user_session_id'))->toBeNull();
});

it('does not share the session id when the request macro is disabled', function (): void {
    config(['user-sessions.request_macro' => false]);

    app(ShareSessionContext::class)->share(($this->makeRequest)());

    expect(Context::get('device'))->toBe('Chrome on macOS')
        ->and(Context::get('user_session_id'))->toBeNull();
});
