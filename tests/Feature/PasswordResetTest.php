<?php

declare(strict_types=1);

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;
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

it('revokes other sessions but keeps the current one on password reset', function (): void {
    session()->start();
    $currentId = session()->getId();

    UserSession::factory()->create([
        'session_id' => $currentId,
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
    UserSession::factory()->create([
        'session_id' => 'other',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new PasswordReset($this->user));

    expect(UserSession::where('session_id', $currentId)->whereNull('revoked_at')->exists())->toBeTrue()
        ->and(UserSession::where('session_id', 'other')->whereNull('revoked_at')->exists())->toBeFalse();
});

it('revokes all sessions on password reset without an active session (e-mail link)', function (): void {
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

    event(new PasswordReset($this->user));

    expect(UserSession::whereNull('revoked_at')->count())->toBe(0);
});

it('does nothing when revoke_on_password_reset is disabled', function (): void {
    config(['user-sessions.revoke_on_password_reset' => false]);

    session()->start();

    UserSession::factory()->create([
        'session_id' => 'other',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new PasswordReset($this->user));

    expect(UserSession::where('session_id', 'other')->whereNull('revoked_at')->exists())->toBeTrue();
});
