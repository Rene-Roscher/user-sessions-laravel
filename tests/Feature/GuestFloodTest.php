<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();
});

// §12 Test 6 — the marketing claim as a test: guest traffic never touches the DB.
it('issues zero user_sessions queries across a flood of guest requests', function (): void {
    $captured = [];

    DB::listen(function ($query) use (&$captured): void {
        if (str_contains($query->sql, 'user_sessions')) {
            $captured[] = $query->sql;
        }
    });

    for ($i = 0; $i < 50; $i++) {
        $this->get('/')->assertOk();
    }

    expect($captured)->toBe([]);

    $this->assertDatabaseEmpty('user_sessions');
});
