<?php

declare(strict_types=1);

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

it('removes the registry row when Logout fires', function (): void {
    session()->start();
    $sessionId = session()->getId();

    UserSession::factory()->create([
        'session_id' => $sessionId,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new Logout('web', $this->user));

    expect(UserSession::where('session_id', $sessionId)->exists())->toBeFalse();
});

it('removes the registry row when CurrentDeviceLogout fires', function (): void {
    session()->start();
    $sessionId = session()->getId();

    UserSession::factory()->create([
        'session_id' => $sessionId,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new CurrentDeviceLogout('web', $this->user));

    expect(UserSession::where('session_id', $sessionId)->exists())->toBeFalse();
});

it('does not touch other sessions on Logout', function (): void {
    session()->start();
    $sessionId = session()->getId();

    UserSession::factory()->create([
        'session_id' => $sessionId,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
    UserSession::factory()->create([
        'session_id' => 'someone-else',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new Logout('web', $this->user));

    expect(UserSession::where('session_id', 'someone-else')->exists())->toBeTrue();
});

it('destroys other-device rows and keeps the current one on OtherDeviceLogout', function (): void {
    session()->start();
    $currentId = session()->getId();

    UserSession::factory()->create([
        'session_id' => $currentId,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
    UserSession::factory()->create([
        'session_id' => 'other-1',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
    UserSession::factory()->create([
        'session_id' => 'other-2',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new OtherDeviceLogout('web', $this->user));

    expect(UserSession::where('session_id', $currentId)->whereNull('revoked_at')->exists())->toBeTrue()
        ->and(UserSession::where('session_id', 'other-1')->exists())->toBeFalse()
        ->and(UserSession::where('session_id', 'other-2')->exists())->toBeFalse();
});

it('revokes all sessions and warns when OtherDeviceLogout fires with no active session', function (): void {
    Log::spy();

    UserSession::factory()->create([
        'session_id' => 's1',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
    UserSession::factory()->create([
        'session_id' => 's2',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    // No session()->start() → no request context.
    event(new OtherDeviceLogout('web', $this->user));

    expect(UserSession::whereNull('revoked_at')->count())->toBe(0);

    // Assert on the listener's own message: the registry additionally warns that
    // current-session detection was unavailable, which is a separate concern.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'SyncOtherDeviceLogout'))
        ->once();
});
