<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * PLAN §12 test 3b — the upsert race, with actual parallelism.
 *
 * The rest of the suite "tests" this with a sequential loop, which cannot fail: the whole
 * claim is that N *simultaneous* requests for one session produce exactly one row and no
 * unique-constraint violation. With thousands of users, concurrent requests per session
 * are not an edge case (multiple tabs, parallel XHR, prefetch) — they are the norm.
 *
 * This captures the exact SQL the recorder emits, then replays it from independent OS
 * processes on separate connections, which is as close to production as a test suite gets.
 * Skipped on SQLite, whose single-writer file lock makes the race unreproducible.
 */
beforeEach(function (): void {
    $this->withoutDefer();
    $this->user = User::create(['email' => 'race@example.com', 'password' => 'password']);
});

/**
 * @return array{sql: string, bindings: array<int, mixed>}
 */
function captureRecorderUpsert(User $user, string $sessionId): array
{
    $captured = null;

    DB::listen(function ($query) use (&$captured): void {
        if ($captured === null && str_contains(strtolower($query->sql), 'insert into')) {
            $captured = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    app(SessionRecorder::class)->record($sessionId, $user, '127.0.0.1', 'Mozilla/5.0');

    expect($captured)->not->toBeNull('failed to capture the recorder upsert');

    return $captured;
}

it('survives concurrent writes for the same session without duplicating or crashing', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    $sessionId = 'race-'.bin2hex(random_bytes(6));

    // Capture the statement under a DIFFERENT session id than the workers will use. The
    // capturing insert lives inside RefreshDatabase's still-open transaction, which holds
    // a lock on that unique-index entry — pointing the workers at the same id would just
    // make all of them wait on this test's own lock and time out.
    $captureId = 'capture-'.bin2hex(random_bytes(6));

    $upsert = captureRecorderUpsert($this->user, $captureId);

    // Derive the binding positions from the statement itself rather than assuming the
    // recorder's array order: Laravel's upsert() ksort()s each row, so the columns come
    // out alphabetical, not in the order they were written.
    preg_match('/insert into \S+ \(([^)]+)\)/i', $upsert['sql'], $matches);

    $columns = array_map(
        static fn (string $column): string => trim($column, ' "`'),
        explode(',', $matches[1] ?? ''),
    );

    $idIndex = array_search('id', $columns, true);
    $sessionIdIndex = array_search('session_id', $columns, true);

    expect($idIndex)->not->toBeFalse()
        ->and($sessionIdIndex)->not->toBeFalse()
        ->and($upsert['bindings'][$sessionIdIndex])->toBe($captureId);

    $config = config('database.connections.'.config('database.default'));

    $worker = tempnam(sys_get_temp_dir(), 'us-race-').'.php';

    file_put_contents($worker, '<?php
[$dsnConfig, $sql, $bindings, $sessionId, $idIndex, $sessionIdIndex] = json_decode(file_get_contents($argv[1]), true);

$dsn = $dsnConfig["driver"] === "pgsql"
    ? "pgsql:host={$dsnConfig["host"]};port={$dsnConfig["port"]};dbname={$dsnConfig["database"]}"
    : "mysql:host={$dsnConfig["host"]};port={$dsnConfig["port"]};dbname={$dsnConfig["database"]};charset=utf8mb4";

// Every worker is a separate request, so each brings its own freshly generated primary
// key — exactly the situation ON CONFLICT/ON DUPLICATE KEY has to collapse into one row.
$bindings[$idIndex] = strtolower(str_pad(dechex(random_int(0, PHP_INT_MAX)), 26, "0"));
$bindings[$sessionIdIndex] = $sessionId;

try {
    $pdo = new PDO($dsn, $dsnConfig["username"], $dsnConfig["password"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Line the workers up on a shared wall-clock tick so they collide for real.
    usleep((int) max(0, ((float) $argv[2] - microtime(true)) * 1000000));

    $pdo->prepare($sql)->execute($bindings);
    echo "OK";
} catch (Throwable $e) {
    echo "FAIL: ".$e->getMessage();
}
');

    $payload = tempnam(sys_get_temp_dir(), 'us-race-payload-');

    // prepareBindings() is what turns Carbon instances into the engine's datetime format;
    // JSON-encoding them raw would ship ISO-8601 that MySQL rejects.
    $bindings = DB::connection()->prepareBindings($upsert['bindings']);

    file_put_contents($payload, json_encode([$config, $upsert['sql'], $bindings, $sessionId, $idIndex, $sessionIdIndex]));

    $startAt = microtime(true) + 1.0;
    $processes = [];

    for ($i = 0; $i < 16; $i++) {
        $processes[] = proc_open(
            ['php', $worker, $payload, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $pipeSets[] = $pipes;
    }

    $results = [];

    foreach ($processes as $index => $process) {
        $results[] = stream_get_contents($pipeSets[$index][1]);
        fclose($pipeSets[$index][1]);
        fclose($pipeSets[$index][2]);
        proc_close($process);
    }

    $failures = array_values(array_filter($results, fn (string $r): bool => ! str_starts_with($r, 'OK')));

    // No unique-constraint violation, no deadlock surfaced to the caller.
    expect($failures)->toBe([], 'concurrent upserts failed: '.implode(' | ', $failures));

    // Verify from a connection of its own. The test's connection sits inside an open
    // transaction, and MySQL's REPEATABLE READ would keep serving it the snapshot taken
    // before the workers committed — reporting 0 rows no matter what actually happened.
    $verifier = new PDO(
        $config['driver'] === 'pgsql'
            ? "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}"
            : "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $count = $verifier->query(
        'select count(*) from user_sessions where session_id = '.$verifier->quote($sessionId)
    )->fetchColumn();

    // Exactly one row despite 16 simultaneous writers, each with its own primary key.
    expect((int) $count)->toBe(1);

    // Clean up the rows the workers committed outside our transaction.
    $verifier->exec('delete from user_sessions where session_id = '.$verifier->quote($sessionId));

    @unlink($worker);
    @unlink($payload);
})->skip(
    fn (): bool => in_array(DB::connection()->getDriverName(), ['sqlite'], true),
    'sqlite serialises writers, so the race cannot be reproduced',
);

it('keeps one row for repeated sequential records', function (): void {
    config(['user-sessions.sync_interval' => 0]);

    for ($i = 0; $i < 5; $i++) {
        app(SessionRecorder::class)->record('sequential-race', $this->user, '127.0.0.1', 'Mozilla/5.0');
    }

    expect(UserSession::where('session_id', 'sequential-race')->count())->toBe(1);
});
