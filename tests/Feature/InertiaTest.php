<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

// These tests intentionally use the REAL deferred lifecycle (no withoutDefer) so the
// response-status-dependent behaviour Inertia relies on (200/303/409) is exercised.
beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();

    $mw = ['web', StartSession::class, RecordSessions::class];
    app('router')->get('/inertia-ok', fn () => response('OK'))->middleware($mw);
    app('router')->get('/inertia-conflict', fn () => response('', 409))->middleware($mw);
    app('router')->get('/inertia-redirect', fn () => redirect('/inertia-ok', 303))->middleware($mw);
});

$inertiaHeaders = [
    'X-Inertia' => 'true',
    'X-Inertia-Version' => '1.0',
    'X-Requested-With' => 'XMLHttpRequest',
];

it('records a session for an Inertia XHR request', function () use ($inertiaHeaders): void {
    $this->actingAs($this->user)
        ->withHeaders($inertiaHeaders)
        ->get('/inertia-ok')
        ->assertOk();

    $this->assertDatabaseHas('user_sessions', [
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
});

it('records on an Inertia redirect (303)', function () use ($inertiaHeaders): void {
    $this->actingAs($this->user)
        ->withHeaders($inertiaHeaders)
        ->get('/inertia-redirect')
        ->assertStatus(303);

    $this->assertDatabaseHas('user_sessions', [
        'user_id' => $this->user->id,
    ]);
});

it('does not record on an Inertia asset-version conflict (409)', function () use ($inertiaHeaders): void {
    // Inertia returns 409 to force a full reload; the deferred sync skips 4xx, so no
    // phantom session row is written and the subsequent full page load records instead.
    $this->actingAs($this->user)
        ->withHeaders($inertiaHeaders)
        ->get('/inertia-conflict')
        ->assertStatus(409);

    expect(UserSession::count())->toBe(0);
});
