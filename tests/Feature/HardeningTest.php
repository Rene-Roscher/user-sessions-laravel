<?php

declare(strict_types=1);

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionRevoked;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\RevokedBy;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create(['email' => 'hard@example.com', 'password' => 'password']);
    $this->withoutDefer();
});

it('generates a ULID when a row is created through Eloquent', function (): void {
    // The recorder hand-rolls its ULID for the raw upsert, which used to hide that the
    // model itself had no key generation — every Eloquent create() failed on the PK.
    $session = UserSession::create([
        'session_id' => 'eloquent-created',
        'user_type' => User::class,
        'user_id' => $this->user->id,
        'last_activity' => now(),
    ]);

    expect($session->getKey())->toBeString()
        ->and(Str::isUlid($session->getKey()))->toBeTrue();
});

it('creates rows through the relation without an explicit id', function (): void {
    $session = $this->user->sessions()->create([
        'session_id' => 'relation-created',
        'last_activity' => now(),
    ]);

    expect(Str::isUlid($session->getKey()))->toBeTrue()
        ->and($session->user_type)->toBe(User::class);
});

it('truncates an oversized user agent to the column width', function (): void {
    // A 4 KB User-Agent is trivially sendable. On MySQL/Postgres an over-long value
    // aborts the upsert — and the debounce marker is already claimed at that point, so
    // that session would never be recorded again. SQLite would not catch this.
    $huge = str_repeat('A', 4096);

    app(SessionRecorder::class)->record('huge-ua', $this->user, '127.0.0.1', $huge);

    $stored = UserSession::where('session_id', 'huge-ua')->firstOrFail();

    expect(mb_strlen((string) $stored->user_agent))->toBe(500)
        ->and($stored->user_agent)->toBe(str_repeat('A', 500));
});

it('keeps a normal user agent untouched', function (): void {
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

    app(SessionRecorder::class)->record('normal-ua', $this->user, '127.0.0.1', $ua);

    expect(UserSession::where('session_id', 'normal-ua')->firstOrFail()->user_agent)->toBe($ua);
});

it('labels password-reset revocations as password-reset', function (): void {
    Event::fake([UserSessionRevoked::class]);

    UserSession::factory()->create([
        'session_id' => 'pw-other',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    Session::start();
    event(new PasswordReset($this->user));

    // A frontend cannot build "you were signed out because your password changed"
    // unless the reason actually reaches the event.
    Event::assertDispatched(UserSessionRevoked::class, fn (UserSessionRevoked $e): bool => $e->revokedBy === RevokedBy::PASSWORD_RESET);
});

it('labels a self-revoke through the model as self', function (): void {
    Event::fake([UserSessionRevoked::class]);

    $session = UserSession::factory()->create([
        'session_id' => 'self-revoke',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $session->revoke();

    Event::assertDispatched(UserSessionRevoked::class, fn (UserSessionRevoked $e): bool => $e->revokedBy === RevokedBy::SELF);
});

it('returns false when revoking an already revoked session through the model', function (): void {
    $session = UserSession::factory()->revoked()->create([
        'session_id' => 'already-gone',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect($session->revoke())->toBeFalse();
});
