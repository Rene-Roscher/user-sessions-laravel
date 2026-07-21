<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Session;

it('registers a User Sessions section on the about command', function (): void {
    $this->artisan('about', ['--only' => 'user_sessions'])->assertSuccessful();
});

it('flags a cache store that cannot debounce', function (): void {
    // An array/null store forgets the marker between requests, so every request writes to
    // the database — the one misconfiguration that quietly removes the reason to use this
    // package at all. It should be visible without reading the source.
    config([
        'user-sessions.cache_store' => 'array',
        'user-sessions.sync_interval' => 180,
    ]);

    $this->artisan('about', ['--only' => 'user_sessions'])
        ->expectsOutputToContain('INEFFECTIVE')
        ->assertSuccessful();
});

it('does not flag a persistent cache store', function (): void {
    config([
        'cache.stores.persistent_probe' => ['driver' => 'file', 'path' => sys_get_temp_dir().'/us-probe-cache'],
        'user-sessions.cache_store' => 'persistent_probe',
        'user-sessions.sync_interval' => 180,
    ]);

    $this->artisan('about', ['--only' => 'user_sessions'])
        ->doesntExpectOutputToContain('INEFFECTIVE')
        ->assertSuccessful();
});

it('reports where last activity is read from', function (): void {
    config(['session.driver' => 'file', 'session.files' => sys_get_temp_dir().'/us-probe-sessions']);
    Session::forgetDrivers();

    $this->artisan('about', ['--only' => 'user_sessions'])
        ->expectsOutputToContain('session store')
        ->assertSuccessful();
});

it('reports the fallback when store resolution is disabled', function (): void {
    config(['user-sessions.resolve_activity_from_store' => false]);

    $this->artisan('about', ['--only' => 'user_sessions'])
        ->expectsOutputToContain('registry table')
        ->assertSuccessful();
});
