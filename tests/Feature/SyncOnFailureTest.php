<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

// Real deferred lifecycle so the 4xx-skip behaviour is genuinely exercised.
beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();

    app('router')->get('/fails', fn () => response('nope', 422))
        ->middleware(['web', StartSession::class, RecordSessions::class]);
});

it('does not sync on a 4xx response by default', function (): void {
    $this->actingAs($this->user)->get('/fails')->assertStatus(422);

    expect(UserSession::count())->toBe(0);
});

it('syncs on a 4xx response when sync_on_failure is enabled', function (): void {
    config(['user-sessions.sync_on_failure' => true]);

    $this->actingAs($this->user)->get('/fails')->assertStatus(422);

    $this->assertDatabaseHas('user_sessions', [
        'user_id' => $this->user->id,
    ]);
});
