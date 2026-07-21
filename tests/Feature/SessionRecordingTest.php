<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
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

it('records a session for an authenticated request', function (): void {
    $response = $this->actingAs($this->user)->get('/');

    $response->assertOk();

    $this->assertDatabaseHas('user_sessions', [
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);
});

it('does not record a session for a guest request', function (): void {
    $this->get('/');

    $this->assertDatabaseEmpty('user_sessions');
});

it('records session with correct metadata', function (): void {
    $response = $this->actingAs($this->user)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36'])
        ->get('/');

    $response->assertOk();

    $session = UserSession::query()->where('user_id', $this->user->id)->first();

    expect($session)->not->toBeNull()
        ->and($session->session_id)->not->toBeEmpty()
        ->and($session->ip_address)->not->toBeNull()
        ->and($session->user_agent)->toContain('Chrome')
        ->and($session->device_type->value)->toBe('desktop')
        ->and($session->platform)->toBe('macOS')
        ->and($session->browser)->toBe('Chrome')
        ->and($session->revoked_at)->toBeNull()
        ->and($session->created_at)->not->toBeNull()
        ->and($session->last_activity)->not->toBeNull();
});

it('records only one session after login regeneration', function (): void {
    Event::fake([UserSessionCreated::class]);

    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertOk();

    $count = UserSession::query()->where('user_id', $this->user->id)->count();

    expect($count)->toBe(1);

    // The other half of PLAN test 2: the single row must carry the POST-regeneration id.
    // A Login-event listener would have captured the pre-regeneration id here and left a
    // ghost row that no request can ever match — the reason there is no Login listener.
    $finalSessionId = $response->getCookie(config('session.cookie'))->getValue();

    expect(UserSession::query()->where('user_id', $this->user->id)->value('session_id'))
        ->toBe($finalSessionId);
});

it('debounces session updates within sync_interval', function (): void {
    config(['user-sessions.sync_interval' => 180]);

    $recorder = app(SessionRecorder::class);
    $sessionId = 'test-session-id-fixed';

    $recorder->record($sessionId, $this->user, '127.0.0.1', 'Chrome');

    // Subsequent records with same session_id are debounced by Cache::add
    $recorder->record($sessionId, $this->user, '127.0.0.2', 'Chrome');

    $count = UserSession::query()->where('user_id', $this->user->id)->count();

    expect($count)->toBe(1);

    $session = UserSession::query()->where('session_id', $sessionId)->first();
    expect($session->ip_address)->toBe('127.0.0.1');
});

it('syncs every request when sync_interval is 0', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    $recorder = app(SessionRecorder::class);
    $sessionId = 'test-session-id-fixed-2';

    $recorder->record($sessionId, $this->user, '127.0.0.1', 'Chrome');
    $recorder->record($sessionId, $this->user, '127.0.0.2', 'Chrome');

    $count = UserSession::query()->where('session_id', $sessionId)->count();

    expect($count)->toBe(1);
});

it('updates last_activity on subsequent requests', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    $recorder = app(SessionRecorder::class);
    $sessionId = 'test-session-id-fixed-3';

    $recorder->record($sessionId, $this->user, '127.0.0.1', 'Chrome');

    $first = UserSession::query()->where('session_id', $sessionId)->first();
    $firstActivity = $first->last_activity->getTimestamp();

    Carbon::setTestNow(now()->addSeconds(5));

    Cache::forget('user-sessions:debounce:'.$sessionId);

    $recorder->record($sessionId, $this->user, '127.0.0.1', 'Chrome');

    $second = UserSession::query()->where('session_id', $sessionId)->first();

    expect($second->last_activity->getTimestamp())->toBeGreaterThan($firstActivity);

    Carbon::setTestNow();
});

it('fires UserSessionCreated event on first session', function (): void {
    Event::fake([UserSessionCreated::class]);

    $this->actingAs($this->user)->get('/');

    Event::assertDispatched(UserSessionCreated::class);
});

it('does not fire UserSessionCreated on subsequent records within debounce window', function (): void {
    config(['user-sessions.sync_interval' => 180]);

    Event::fake([UserSessionCreated::class]);

    $recorder = app(SessionRecorder::class);
    $sessionId = 'test-session-id-event';

    $recorder->record($sessionId, $this->user, '127.0.0.1', 'Chrome');
    $recorder->record($sessionId, $this->user, '127.0.0.1', 'Chrome');

    Event::assertDispatchedTimes(UserSessionCreated::class, 1);
});

it('fires UserSessionCreated with isNewDevice=true for first device', function (): void {
    Event::fake([UserSessionCreated::class]);

    $this->actingAs($this->user)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36'])
        ->get('/');

    Event::assertDispatched(function (UserSessionCreated $event): bool {
        return $event->isNewDevice === true;
    });
});
