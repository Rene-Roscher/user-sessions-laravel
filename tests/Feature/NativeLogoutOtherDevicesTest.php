<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * PLAN §12 test 7: the *native* Auth::logoutOtherDevices() — the call that goes around
 * the package entirely. Firing the OtherDeviceLogout event by hand (as the rest of the
 * suite does) proves the listener works, not that Laravel actually reaches it.
 *
 * Both variants matter: without the auth.session middleware Laravel only rehashes the
 * password and leaves other sessions alive in the store, which is exactly the drift this
 * listener exists to close.
 */
function cookieValueOf(TestResponse $response): string
{
    return $response->getCookie(config('session.cookie'))->getValue();
}

beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->sessionPath = sys_get_temp_dir().'/user-sessions-native-'.bin2hex(random_bytes(4));
    mkdir($this->sessionPath);

    config(['session.driver' => 'file', 'session.files' => $this->sessionPath]);

    $this->user = User::create(['email' => 'native@example.com', 'password' => 'password']);

    $this->boundary = function (): void {
        Session::forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
    };

    $this->login = function (): string {
        ($this->boundary)();

        $response = $this->post('/login', [
            'email' => 'native@example.com',
            'password' => 'password',
        ])->assertOk();

        $sessionId = $response->getCookie(config('session.cookie'))->getValue();

        $this->withCookie(config('session.cookie'), $sessionId);

        return $sessionId;
    };

    $this->registerOtherDevice = function (): void {
        ($this->boundary)();

        Session::getHandler()->write('native-other-device', 'payload');

        UserSession::factory()->create([
            'session_id' => 'native-other-device',
            'user_type' => User::class,
            'user_id' => $this->user->id,
        ]);
    };
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->sessionPath.'/*') ?: []);
    rmdir($this->sessionPath);
});

it('destroys other sessions store-side without the auth.session middleware', function (): void {
    Route::post('/logout-others', function (Request $request): string {
        Auth::logoutOtherDevices('password');

        return 'ok';
    })->middleware(['web', 'auth']);

    $current = ($this->login)();
    ($this->registerOtherDevice)();

    ($this->boundary)();
    $this->post('/logout-others')->assertOk();

    // Plain Laravel would only rehash the password here; the other device would keep
    // working until its session expired. The package closes that gap.
    expect(Session::getHandler()->read('native-other-device'))->toBe('')
        ->and(UserSession::where('session_id', 'native-other-device')->exists())->toBeFalse()
        ->and(UserSession::where('session_id', $current)->exists())->toBeTrue();
});

it('destroys other sessions with the auth.session middleware', function (): void {
    Route::post('/logout-others-guarded', function (Request $request): string {
        Auth::logoutOtherDevices('password');

        return 'ok';
    })->middleware(['web', 'auth', 'auth.session']);

    $current = ($this->login)();
    ($this->registerOtherDevice)();

    ($this->boundary)();
    $this->post('/logout-others-guarded')->assertOk();

    expect(Session::getHandler()->read('native-other-device'))->toBe('')
        ->and(UserSession::where('session_id', 'native-other-device')->exists())->toBeFalse()
        ->and(UserSession::where('session_id', $current)->exists())->toBeTrue();
});

it('keeps the caller authenticated after logging other devices out', function (): void {
    Route::post('/logout-others', function (Request $request): string {
        Auth::logoutOtherDevices('password');

        return 'ok';
    })->middleware(['web', 'auth']);

    Route::get('/whoami', fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? 'guest'))
        ->middleware(['web']);

    ($this->login)();
    ($this->registerOtherDevice)();

    ($this->boundary)();
    $response = $this->post('/logout-others')->assertOk();

    // logoutOtherDevices() regenerates the caller's session, so follow the new cookie.
    $this->withCookie(config('session.cookie'), cookieValueOf($response));

    ($this->boundary)();
    $this->get('/whoami')->assertSee((string) $this->user->id);
});
