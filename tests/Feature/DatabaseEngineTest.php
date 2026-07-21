<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Facades\UserSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\FailureIsolation;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * Engine-specific behaviour that SQLite structurally cannot exercise.
 *
 * SQLite does not enforce VARCHAR lengths and does not validate UTF-8, so the fixes for
 * over-long and malformed User-Agents were unfalsifiable there: the tests passed whether
 * or not the sanitising worked. Run with DB_CONNECTION=mysql|pgsql these assertions have
 * teeth — remove the sanitising and they fail with "Data too long" / "invalid byte
 * sequence for encoding UTF8".
 */
beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->user = User::create(['email' => 'engine@example.com', 'password' => 'password']);
    $this->engine = DB::connection()->getDriverName();
});

it('stores an oversized user agent without the engine rejecting the row', function (): void {
    app(SessionRecorder::class)->record('engine-long-ua', $this->user, '127.0.0.1', str_repeat('Ä', 4096));

    $stored = UserSession::where('session_id', 'engine-long-ua')->firstOrFail();

    // Multibyte matters: 500 characters must stay within the column, not 500 bytes.
    expect(mb_strlen((string) $stored->user_agent))->toBe(500);
})->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite', 'sqlite does not enforce column widths');

it('stores a malformed-UTF-8 user agent without the engine rejecting the row', function (): void {
    app(SessionRecorder::class)->record('engine-bad-utf8', $this->user, '127.0.0.1', "Mozilla/5.0 \xC3\x28\xFF\xFE invalid");

    $stored = UserSession::where('session_id', 'engine-bad-utf8')->firstOrFail();

    expect(mb_check_encoding((string) $stored->user_agent, 'UTF-8'))->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite', 'sqlite does not validate encodings');

it('keeps exactly one row when the same session is upserted repeatedly', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    // The unique constraint on session_id plus ON CONFLICT/ON DUPLICATE KEY semantics
    // differ per engine; this is the race-safety claim expressed as far as a single
    // process can express it.
    for ($i = 0; $i < 10; $i++) {
        app(SessionRecorder::class)->record('engine-upsert', $this->user, '10.0.0.'.$i, 'Mozilla/5.0');
    }

    expect(UserSession::where('session_id', 'engine-upsert')->count())->toBe(1)
        ->and(UserSession::where('session_id', 'engine-upsert')->value('ip_address'))->toBe('10.0.0.9');
});

it('rejects a genuinely duplicated session id', function (): void {
    // Proves the unique index actually exists on this engine — without it the upsert
    // above would silently create duplicates instead of updating.
    UserSession::factory()->create([
        'session_id' => 'engine-unique',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    // The insert runs in a nested transaction so the expected failure only rolls back to
    // a SAVEPOINT. Without it Postgres marks the surrounding transaction as aborted and
    // the test teardown dies on "current transaction is aborted" instead of passing.
    expect(fn () => DB::transaction(fn () => DB::table('user_sessions')->insert([
        'id' => strtolower((string) Str::ulid()),
        'session_id' => 'engine-unique',
        'user_type' => User::class,
        'user_id' => (string) $this->user->id,
        'last_activity' => now(),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('round-trips datetime columns without engine defaults interfering', function (): void {
    // PLAN §6.1: MySQL gives a non-nullable TIMESTAMP an implicit
    // DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, which would silently rewrite
    // created_at on every upsert. The migration uses dateTime precisely to avoid that.
    config(['user-sessions.sync_interval' => 0]);

    app(SessionRecorder::class)->record('engine-times', $this->user, '127.0.0.1', 'Mozilla/5.0');

    $created = UserSession::where('session_id', 'engine-times')->firstOrFail()->created_at;

    $this->travel(5)->seconds();
    app(SessionRecorder::class)->record('engine-times', $this->user, '127.0.0.1', 'Mozilla/5.0');

    $row = UserSession::where('session_id', 'engine-times')->firstOrFail();

    expect($row->created_at?->timestamp)->toBe($created?->timestamp)
        ->and($row->last_activity?->timestamp)->toBeGreaterThan($created?->timestamp);

    $this->travelBack();
});

it('stores a ULID primary key as a string, not a truncated integer', function (): void {
    app(SessionRecorder::class)->record('engine-ulid', $this->user, '127.0.0.1', 'Mozilla/5.0');

    $id = UserSession::where('session_id', 'engine-ulid')->value('id');

    expect($id)->toBeString()
        ->and(strlen((string) $id))->toBe(26);
});

it('keeps a string user_id intact for non-integer keyed models', function (): void {
    // §6.1 chose explicit string morph columns over morphs() precisely so UUID/ULID user
    // keys survive on Postgres, where a bigint column would reject them outright.
    $uuid = '018f3a2b-1c4d-7e8f-9a0b-1c2d3e4f5a6b';

    DB::table('user_sessions')->insert([
        'id' => strtolower((string) Str::ulid()),
        'session_id' => 'engine-uuid-user',
        'user_type' => 'App\\Models\\UuidUser',
        'user_id' => $uuid,
        'last_activity' => now(),
        'created_at' => now(),
    ]);

    expect(DB::table('user_sessions')->where('session_id', 'engine-uuid-user')->value('user_id'))->toBe($uuid);
});

it('does not swallow a database error raised inside a transaction', function (): void {
    // Postgres aborts the whole transaction after any failed statement, so absorbing the
    // error would replace the real cause with a cascade of "current transaction is
    // aborted" further up the stack.
    $failure = new QueryException('pgsql', 'select 1', [], new RuntimeException('boom'));

    DB::transaction(function () use ($failure): void {
        expect(FailureIsolation::canSwallow($failure))->toBeFalse();
    });

    expect(FailureIsolation::canSwallow($failure))->toBe(DB::transactionLevel() === 0);
});

it('still absorbs a non-database failure inside a transaction', function (): void {
    DB::transaction(function (): void {
        expect(FailureIsolation::canSwallow(new RuntimeException('a broken listener')))->toBeTrue();
    });
});

it('revokes across engines and really destroys the stored session', function (): void {
    Session::getHandler()->write('engine-revoke', 'payload');

    UserSession::factory()->create([
        'session_id' => 'engine-revoke',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect(UserSessions::revoke('engine-revoke'))->toBeTrue()
        ->and(Session::getHandler()->read('engine-revoke'))->toBe('')
        ->and(UserSession::where('session_id', 'engine-revoke')->firstOrFail()->revoked_at)->not->toBeNull();
});
