<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Facades\UserSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\RevokedBy;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * PLAN §9.4 / test 4c: revoking from a queue worker, an artisan command or tinker must
 * really end the session in the store. Gating the handler destroy on Session::isStarted()
 * used to make these calls a silent no-op that still reported success — the registry said
 * "revoked" while every device stayed logged in until natural expiry.
 */
beforeEach(function (): void {
    $this->user = User::create(['email' => 'cli@example.com', 'password' => 'password']);
    $this->withoutDefer();

    $this->storeSession = function (string $sessionId, string $payload = 'live-payload'): void {
        Session::getHandler()->write($sessionId, $payload);
    };
});

it('destroys the stored session when revoking a single session outside a request', function (): void {
    ($this->storeSession)('cli-single');

    UserSession::factory()->create([
        'session_id' => 'cli-single',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect(Session::isStarted())->toBeFalse();

    expect(UserSessions::revoke('cli-single', RevokedBy::ADMIN))->toBeTrue()
        ->and(Session::getHandler()->read('cli-single'))->toBe('')
        ->and(UserSession::where('session_id', 'cli-single')->first()->revoked_at)->not->toBeNull();
});

it('destroys every stored session when revoking all outside a request', function (): void {
    foreach (['cli-a', 'cli-b', 'cli-c'] as $id) {
        ($this->storeSession)($id);

        UserSession::factory()->create([
            'session_id' => $id,
            'user_type' => User::class,
            'user_id' => $this->user->id,
        ]);
    }

    expect(UserSessions::revokeAll($this->user))->toBe(3);

    foreach (['cli-a', 'cli-b', 'cli-c'] as $id) {
        expect(Session::getHandler()->read($id))->toBe('');
    }
});

it('destroys other stored sessions when revoking others outside a request', function (): void {
    ($this->storeSession)('cli-keep');
    ($this->storeSession)('cli-drop');

    foreach (['cli-keep', 'cli-drop'] as $id) {
        UserSession::factory()->create([
            'session_id' => $id,
            'user_type' => User::class,
            'user_id' => $this->user->id,
        ]);
    }

    expect(UserSessions::revokeOthers($this->user, 'cli-keep'))->toBe(1)
        ->and(Session::getHandler()->read('cli-drop'))->toBe('')
        ->and(Session::getHandler()->read('cli-keep'))->toBe('live-payload');
});

it('warns that current-session detection is unavailable outside a request', function (): void {
    Log::spy();

    ($this->storeSession)('cli-warn');

    UserSession::factory()->create([
        'session_id' => 'cli-warn',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    UserSessions::revoke('cli-warn');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'outside request context'))
        ->once();
});

it('does not warn when revoking a foreign session inside a request', function (): void {
    Log::spy();

    Session::start();
    ($this->storeSession)('in-request');

    UserSession::factory()->create([
        'session_id' => 'in-request',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    UserSessions::revoke('in-request');

    Log::shouldNotHaveReceived('warning');
});

it('reads a payload outside a request context', function (): void {
    // payload() is pitched as debugging gold, and tinker is exactly where it is used.
    ($this->storeSession)('cli-payload', serialize(['foo' => 'bar']));

    UserSession::factory()->create([
        'session_id' => 'cli-payload',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect(Session::isStarted())->toBeFalse()
        ->and(UserSessions::payload('cli-payload'))->toBe(['foo' => 'bar']);
});
