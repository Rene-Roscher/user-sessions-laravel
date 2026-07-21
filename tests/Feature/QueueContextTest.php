<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

/**
 * PLAN §12 test 11, the half the suite was missing: a job dispatched during the request
 * must carry the device context. Asserting Context::get() inside the same process proves
 * nothing about dehydration — the payload that actually reaches the worker is what counts,
 * so this inspects the serialized job body on a real queue connection.
 */
class ContextProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public function handle(): void {}
}

beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();

    $this->user = User::create(['email' => 'queue@example.com', 'password' => 'password']);

    config(['queue.default' => 'database', 'queue.connections.database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]]);

    Schema::create('jobs', function ($table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Route::get('/dispatch-job', function (Request $request): string {
        ContextProbeJob::dispatch();

        return 'queued';
    })->middleware(['web', RecordSessions::class]);
});

afterEach(function (): void {
    Schema::dropIfExists('jobs');
});

it('carries the device context into a job dispatched during the request', function (): void {
    $this->actingAs($this->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36')
        ->get('/dispatch-job')
        ->assertOk();

    $payload = DB::table('jobs')->value('payload');

    expect($payload)->not->toBeNull();

    // Laravel dehydrates Context into the job payload under this key and rehydrates it
    // in the worker, so "the failed payment job came from the Chrome/macOS device" holds
    // up in the log without any application code.
    $decoded = json_decode((string) $payload, true);

    expect($decoded)->toHaveKey('illuminate:log:context')
        ->and(json_encode($decoded['illuminate:log:context']))->toContain('Chrome on macOS');
});

it('does not carry a session id into the job payload', function (): void {
    $this->actingAs($this->user)->get('/dispatch-job')->assertOk();

    $sessionId = session()->getId();
    $payload = (string) DB::table('jobs')->value('payload');

    // Queue payloads are persisted and often shipped to third-party backends.
    expect($payload)->not->toContain($sessionId);
});

it('dispatches guest requests without any device context', function (): void {
    // Send a UA that WOULD produce a recognisable label, otherwise this asserts the
    // absence of a string that could never have appeared and would stay green even if
    // guest device fingerprints started leaking into every queue payload.
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36')
        ->get('/dispatch-job')
        ->assertOk();

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)->not->toContain('Chrome on macOS')
        ->and($payload)->not->toContain('illuminate:log:context');
});

it('proves the guest assertion can fail', function (): void {
    // Control for the test above: the same UA on an *authenticated* request must produce
    // exactly the label the guest test looks for.
    $this->actingAs($this->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36')
        ->get('/dispatch-job')
        ->assertOk();

    expect((string) DB::table('jobs')->value('payload'))->toContain('Chrome on macOS');
});
