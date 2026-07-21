<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
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

// §12 Test 3b — with sync_interval=0 the upsert must stay race-safe: many records of the
// same session produce exactly one row and never a unique-constraint exception.
it('keeps exactly one row for repeated records of the same session at sync_interval=0', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    $recorder = app(SessionRecorder::class);

    for ($i = 0; $i < 5; $i++) {
        $recorder->record('same-session', $this->user, "10.0.0.{$i}", 'Chrome');
    }

    expect(UserSession::where('session_id', 'same-session')->count())->toBe(1);
});

it('updates mutable columns on subsequent upserts but never duplicates', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    $recorder = app(SessionRecorder::class);
    $recorder->record('sess', $this->user, '1.1.1.1', 'Chrome');
    $recorder->record('sess', $this->user, '9.9.9.9', 'Chrome');

    $rows = UserSession::where('session_id', 'sess')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->ip_address)->toBe('9.9.9.9');
});

// §12 Test 19 — the recorder is a stateless singleton; recording two different users
// back-to-back must attribute each row correctly (no Octane state bleed).
it('attributes concurrent records to the correct user without state bleed', function (): void {
    $other = User::create([
        'email' => 'other@example.com',
        'password' => 'password',
    ]);

    $recorder = app(SessionRecorder::class);
    $recorder->record('sess-a', $this->user, '1.1.1.1', 'Chrome');
    $recorder->record('sess-b', $other, '2.2.2.2', 'Firefox');

    $a = UserSession::where('session_id', 'sess-a')->first();
    $b = UserSession::where('session_id', 'sess-b')->first();

    expect($a->user_id)->toEqual($this->user->id)
        ->and($a->ip_address)->toBe('1.1.1.1')
        ->and($b->user_id)->toEqual($other->id)
        ->and($b->ip_address)->toBe('2.2.2.2')
        ->and($a->user_id)->not->toEqual($b->user_id);
});
