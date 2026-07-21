<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use ReneRoscher\UserSessions\Context\ShareSessionContext;
use ReneRoscher\UserSessions\Contracts\SessionActivityResolver;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\CurrentUserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;
use ReneRoscher\UserSessions\UserSessionRegistry;

/**
 * PLAN §12 test 19 — the Octane state-bleed test, driven end to end.
 *
 * The arch tests prove *structurally* that no service holds a Request or a user. This
 * proves it *behaviourally*: several requests from different users pass through one booted
 * application and one set of singletons, exactly as they would in an Octane worker, and
 * nothing may carry over. A leak here is the worst class of bug this package could have —
 * one user seeing, or revoking, another user's device.
 */
beforeEach(function (): void {
    Cache::flush();
    Context::flush();
    $this->withoutDefer();

    $this->alice = User::create(['email' => 'alice@example.com', 'password' => 'password']);
    $this->bob = User::create(['email' => 'bob@example.com', 'password' => 'password']);

    Route::get('/probe', function (Request $request): array {
        return [
            'user' => $request->user()?->getAuthIdentifier(),
            'device' => Context::get('device'),
            'session_context' => Context::get('user_session_id'),
        ];
    })->middleware(['web', RecordSessions::class]);
});

it('attributes rows to the right user across requests through one booted app', function (): void {
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
    $firefox = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';

    // Same container, same singletons — only the request and the user differ.
    $this->actingAs($this->alice)->withHeader('User-Agent', $chrome)->get('/probe')->assertOk();
    $this->actingAs($this->bob)->withHeader('User-Agent', $firefox)->get('/probe')->assertOk();
    $this->actingAs($this->alice)->withHeader('User-Agent', $chrome)->get('/probe')->assertOk();

    $aliceRows = UserSession::where('user_id', $this->alice->id)->get();
    $bobRows = UserSession::where('user_id', $this->bob->id)->get();

    expect($aliceRows)->not->toBeEmpty()
        ->and($bobRows)->not->toBeEmpty();

    // No row may be attributed to the wrong user, and no device label may cross over.
    foreach ($aliceRows as $row) {
        expect($row->browser)->toBe('Chrome');
    }

    foreach ($bobRows as $row) {
        expect($row->browser)->toBe('Firefox');
    }
});

it('does not leak one user\'s device context into the next request', function (): void {
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

    $this->actingAs($this->alice)->withHeader('User-Agent', $chrome)->get('/probe')
        ->assertJsonPath('device', 'Chrome on macOS');

    // Context is a scoped binding, which Octane resets between requests. Simulate that
    // boundary and assert the next (guest) request starts clean rather than inheriting
    // the previous user's device.
    Context::flush();
    Auth::forgetGuards();

    $this->app->forgetInstance('request');

    $this->get('/probe')
        ->assertJsonPath('user', null)
        ->assertJsonPath('device', null);
});

it('keeps singletons free of per-request state after serving a request', function (): void {
    $this->actingAs($this->alice)
        ->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/121.0')
        ->get('/probe')
        ->assertOk();

    // The container instances that survive a request in an Octane worker.
    foreach ([SessionRecorder::class, UserSessionRegistry::class, ShareSessionContext::class, SessionActivityResolver::class] as $service) {
        $instance = app($service);

        foreach ((new ReflectionObject($instance))->getProperties() as $property) {
            $value = $property->getValue($instance);

            expect($value)->not->toBeInstanceOf(Request::class)
                ->and($value)->not->toBeInstanceOf(User::class)
                ->and($value)->not->toBeInstanceOf(UserSession::class);
        }
    }
});

it('resolves the current session per request, not once per worker', function (): void {
    // CurrentUserSession memoizes into the Request's attribute bag. If it ever memoized
    // anywhere longer-lived, the second user here would be handed the first user's row.
    Session::start();

    // Laravel only accepts 40-character alphanumeric session ids; anything else makes
    // Store::setId() silently generate a fresh one, which would make this test pass by
    // resolving nothing at all.
    $aliceId = Str::random(40);
    $bobId = Str::random(40);

    $aliceSession = UserSession::factory()->create([
        'session_id' => $aliceId,
        'user_type' => User::class,
        'user_id' => $this->alice->id,
    ]);

    $bobSession = UserSession::factory()->create([
        'session_id' => $bobId,
        'user_type' => User::class,
        'user_id' => $this->bob->id,
    ]);

    $resolve = function (User $user, string $sessionId): ?UserSession {
        $request = Request::create('/probe', 'GET');
        $request->setLaravelSession(session()->driver());
        $request->session()->setId($sessionId);
        $request->setUserResolver(fn (): User => $user);

        return CurrentUserSession::resolve($request);
    };

    expect($resolve($this->alice, $aliceId)?->getKey())->toBe($aliceSession->getKey())
        ->and($resolve($this->bob, $bobId)?->getKey())->toBe($bobSession->getKey())
        // And a user must never resolve someone else's session id.
        ->and($resolve($this->bob, $aliceId))->toBeNull();
});

it('caps how many sessions a device list will hydrate', function (): void {
    config(['user-sessions.max_listed' => 5]);

    for ($i = 0; $i < 12; $i++) {
        storeSession('flood-'.$i);

        UserSession::factory()->create([
            'session_id' => 'flood-'.$i,
            'user_type' => User::class,
            'user_id' => $this->alice->id,
        ]);
    }

    // Nothing stops a scripted client from accumulating rows; a device list must not turn
    // that into an unbounded read plus one store lookup per row.
    expect(app(UserSessionRegistry::class)->for($this->alice))->toHaveCount(5);
});
