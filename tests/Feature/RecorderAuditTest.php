<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\CustomUserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

const UA_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();
});

// Audit regression: the "announced" marker used to expire after new_device_window (24h),
// re-firing UserSessionCreated (and the notification) every ~24h for a still-active session.
it('does not re-announce a still-active session after new_device_window elapses', function (): void {
    config([
        'user-sessions.sync_interval' => 0,
        'user-sessions.new_device_window' => 1,   // 1 second
        'session.lifetime' => 120,                 // marker TTL is tied to this, not the window
    ]);

    Event::fake([UserSessionCreated::class]);
    $recorder = app(SessionRecorder::class);

    $recorder->record('long-session', $this->user, '1.1.1.1', UA_CHROME);

    // Far beyond new_device_window, but the session is still active.
    Carbon::setTestNow(now()->addSeconds(30));
    $recorder->record('long-session', $this->user, '1.1.1.1', UA_CHROME);
    Carbon::setTestNow();

    Event::assertDispatchedTimes(UserSessionCreated::class, 1);
});

// Audit regression: the created-event model lookup was hard-coded to the base UserSession
// instead of the configured user-sessions.model.
it('dispatches the created event using the configured model', function (): void {
    config(['user-sessions.model' => CustomUserSession::class]);

    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('sess', $this->user, '1.1.1.1', UA_CHROME);

    Event::assertDispatched(fn (UserSessionCreated $e): bool => $e->session instanceof CustomUserSession);
});
