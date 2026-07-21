<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Events\UserSessionRevoked;
use ReneRoscher\UserSessions\Facades\UserSessions as UserSessionsFacade;
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

// §12 Test 15 — the global events kill-switch (on state is covered across the other suites).
it('dispatches no package events when events are disabled', function (): void {
    config(['user-sessions.events' => false]);

    Event::fake([UserSessionCreated::class, UserSessionRevoked::class]);

    app(SessionRecorder::class)->record('s', $this->user, '1.1.1.1', 'Chrome');

    $session = UserSession::where('session_id', 's')->first();
    UserSessionsFacade::revoke($session);

    Event::assertNotDispatched(UserSessionCreated::class);
    Event::assertNotDispatched(UserSessionRevoked::class);
});

it('still records the row when events are disabled', function (): void {
    config(['user-sessions.events' => false]);

    app(SessionRecorder::class)->record('s', $this->user, '1.1.1.1', 'Chrome');

    $this->assertDatabaseHas('user_sessions', ['session_id' => 's']);
});

it('debounces writes when sync_interval is greater than zero', function (): void {
    config(['user-sessions.sync_interval' => 180]);

    $recorder = app(SessionRecorder::class);
    $recorder->record('debounced', $this->user, '1.1.1.1', 'Chrome');
    $recorder->record('debounced', $this->user, '2.2.2.2', 'Chrome');

    $session = UserSession::where('session_id', 'debounced')->first();

    expect(UserSession::where('session_id', 'debounced')->count())->toBe(1)
        ->and($session->ip_address)->toBe('1.1.1.1');
});
