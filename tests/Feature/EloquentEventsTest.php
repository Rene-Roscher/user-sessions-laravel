<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
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

// §12 Test 17 — the recorder writes through the query builder (upsert), so no Eloquent
// model events fire for third-party observers (activity-log, auditing, ...).
it('fires no Eloquent creating/updating events while recording', function (): void {
    $fired = [];

    UserSession::creating(function () use (&$fired): void {
        $fired[] = 'creating';
    });
    UserSession::updating(function () use (&$fired): void {
        $fired[] = 'updating';
    });
    UserSession::saving(function () use (&$fired): void {
        $fired[] = 'saving';
    });

    $recorder = app(SessionRecorder::class);
    $recorder->record('s', $this->user, '1.1.1.1', 'Chrome');
    $recorder->record('s', $this->user, '1.1.1.2', 'Chrome');

    expect($fired)->toBe([]);

    UserSession::flushEventListeners();
});

it('still fires exactly one package UserSessionCreated event on first record', function (): void {
    Event::fake([UserSessionCreated::class]);

    $recorder = app(SessionRecorder::class);
    $recorder->record('s', $this->user, '1.1.1.1', 'Chrome');
    $recorder->record('s', $this->user, '1.1.1.1', 'Chrome');

    Event::assertDispatchedTimes(UserSessionCreated::class, 1);
});
