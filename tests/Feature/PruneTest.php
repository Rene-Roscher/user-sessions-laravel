<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Events\UserSessionsPruned;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();

    $this->lifetime = (int) config('session.lifetime', 120);
    $this->pruneDays = (int) config('user-sessions.prune_after_days', 30);
});

function makeSession(User $user, string $id, array $overrides = []): UserSession
{
    return UserSession::factory()->create(array_merge([
        'session_id' => $id,
        'user_type' => User::class,
        'user_id' => $user->id,
    ], $overrides));
}

it('prunes expired and long-revoked sessions but keeps active ones', function (): void {
    $active = makeSession($this->user, 'active', [
        'last_activity' => now(),
        'revoked_at' => null,
    ]);
    $expired = makeSession($this->user, 'expired', [
        'last_activity' => now()->subMinutes($this->lifetime)->subDays($this->pruneDays + 1),
        'revoked_at' => null,
    ]);
    $revoked = makeSession($this->user, 'revoked', [
        'last_activity' => now()->subDays($this->pruneDays + 1),
        'revoked_at' => now()->subDays($this->pruneDays + 1),
    ]);

    $this->artisan('model:prune', ['--model' => [UserSession::class]])->assertExitCode(0);

    expect(UserSession::find($active->id))->not->toBeNull()
        ->and(UserSession::find($expired->id))->toBeNull()
        ->and(UserSession::find($revoked->id))->toBeNull();
});

it('keeps recently-revoked sessions until the retention window passes', function (): void {
    $recentlyRevoked = makeSession($this->user, 'recent', [
        'last_activity' => now()->subMinutes(5),
        'revoked_at' => now()->subMinutes(5),
    ]);

    $this->artisan('model:prune', ['--model' => [UserSession::class]])->assertExitCode(0);

    expect(UserSession::find($recentlyRevoked->id))->not->toBeNull();
});

it('fires UserSessionsPruned with the pruned count', function (): void {
    $captured = null;

    Event::listen(UserSessionsPruned::class, function (UserSessionsPruned $event) use (&$captured): void {
        $captured = $event->count;
    });

    makeSession($this->user, 'old-1', [
        'last_activity' => now()->subDays($this->pruneDays + 1),
        'revoked_at' => now()->subDays($this->pruneDays + 1),
    ]);
    makeSession($this->user, 'old-2', [
        'last_activity' => now()->subMinutes($this->lifetime)->subDays($this->pruneDays + 1),
        'revoked_at' => null,
    ]);

    $this->artisan('model:prune', ['--model' => [UserSession::class]]);

    expect($captured)->toBe(2);
});

it('does not fire UserSessionsPruned when events are disabled', function (): void {
    config(['user-sessions.events' => false]);
    $fired = false;

    Event::listen(UserSessionsPruned::class, function () use (&$fired): void {
        $fired = true;
    });

    makeSession($this->user, 'old', [
        'last_activity' => now()->subDays($this->pruneDays + 1),
        'revoked_at' => now()->subDays($this->pruneDays + 1),
    ]);

    $this->artisan('model:prune', ['--model' => [UserSession::class]]);

    expect($fired)->toBeFalse();
});
