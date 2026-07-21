<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use ReneRoscher\UserSessions\Facades\UserSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\RevokedBy;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * PLAN §12 tests 4 and 4b — the security claim the package is sold on: revoking is
 * effective immediately, so the next request from that device is a guest. Every other
 * revoke test asserts registry state, which would still pass if the session store were
 * never touched at all.
 *
 * Two things make this honest rather than self-confirming:
 *  - the file driver, because the "array" driver keeps session attributes on the Store
 *    singleton and Store::start() merges them back in, so a destroyed session would still
 *    look alive inside one test process;
 *  - crossing a real request boundary before every simulated request, because a live
 *    guard and a warm Store are exactly the in-memory state a new PHP worker would not
 *    have. Without both, these assertions pass for the wrong reason.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->sessionPath = sys_get_temp_dir().'/user-sessions-roundtrip-'.bin2hex(random_bytes(4));
    mkdir($this->sessionPath);

    config(['session.driver' => 'file', 'session.files' => $this->sessionPath]);

    $this->user = User::create(['email' => 'roundtrip@example.com', 'password' => 'password']);

    Route::get('/whoami', fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? 'guest'))
        ->middleware(['web']);

    Route::post('/self-revoke', function (Request $request): string {
        UserSessions::revoke($request->session()->getId(), RevokedBy::SELF);

        return 'revoked';
    })->middleware(['web']);

    /**
     * Drop everything a fresh PHP worker would not carry over.
     *
     * forgetInstance('session.store') matters as much as the other two: SessionGuard is
     * constructed with that container singleton, so without it the guard keeps reading
     * the Store object from the previous request and reports an authenticated user even
     * when the current request's session is empty — which would quietly invert the result
     * of every assertion in this file.
     */
    $this->boundary = function (): void {
        Session::forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
    };

    $this->cookieOf = fn (TestResponse $response): string => $response->getCookie(config('session.cookie'))->getValue();

    /** A request as a browser would make it: new process state, same session cookie. */
    $this->visit = function (string $uri): TestResponse {
        ($this->boundary)();

        return $this->get($uri);
    };

    // Log in for real, so the session store — not an in-memory actingAs() user — is what
    // authenticates the following requests.
    $this->login = function (): string {
        ($this->boundary)();

        $response = $this->post('/login', [
            'email' => 'roundtrip@example.com',
            'password' => 'password',
        ])->assertOk();

        $sessionId = ($this->cookieOf)($response);

        $this->withCookie(config('session.cookie'), $sessionId);

        return $sessionId;
    };
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->sessionPath.'/*') ?: []);
    rmdir($this->sessionPath);
});

it('authenticates a following request from the same session cookie', function (): void {
    $sessionId = ($this->login)();

    // Control: without this passing, any "becomes a guest" assertion proves nothing.
    ($this->visit)('/whoami')->assertOk()->assertSee((string) $this->user->id);

    expect(UserSession::where('session_id', $sessionId)->exists())->toBeTrue();
});

it('makes the next request a guest after the session is revoked', function (): void {
    $sessionId = ($this->login)();

    ($this->visit)('/whoami')->assertSee((string) $this->user->id);

    // Revoke the way an admin would: from outside the request.
    ($this->boundary)();

    expect(UserSessions::revoke($sessionId, RevokedBy::ADMIN))->toBeTrue();

    ($this->visit)('/whoami')->assertOk()->assertSee('guest');
});

it('makes other devices guests after revokeOthers', function (): void {
    $current = ($this->login)();

    ($this->boundary)();
    Session::getHandler()->write('other-device-session', 'payload');

    UserSession::factory()->create([
        'session_id' => 'other-device-session',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    UserSessions::revokeOthers($this->user, $current);

    // The current device survives...
    ($this->visit)('/whoami')->assertSee((string) $this->user->id);

    // ...and the other device is gone from the store, not merely flagged in the table.
    expect(Session::getHandler()->read('other-device-session'))->toBe('');
});

it('does not resurrect the session when a user revokes their own current session', function (): void {
    ($this->login)();

    ($this->boundary)();
    $response = $this->post('/self-revoke')->assertOk();

    // StartSession writes the in-memory payload back at the end of the request; without
    // the invalidate() guard that would resurrect the session we just destroyed.
    $this->withCookie(config('session.cookie'), ($this->cookieOf)($response));

    ($this->visit)('/whoami')->assertSee('guest');
});

it('leaves other users untouched when revoking', function (): void {
    ($this->login)();

    $other = User::create(['email' => 'bystander@example.com', 'password' => 'password']);

    ($this->boundary)();
    Session::getHandler()->write('bystander-session', 'payload');

    UserSession::factory()->create([
        'session_id' => 'bystander-session',
        'user_type' => User::class,
        'user_id' => $other->id,
    ]);

    UserSessions::revokeAll($this->user);

    expect(Session::getHandler()->read('bystander-session'))->toBe('payload')
        ->and(UserSession::where('session_id', 'bystander-session')->firstOrFail()->revoked_at)->toBeNull();

    ($this->visit)('/whoami')->assertSee('guest');
});
