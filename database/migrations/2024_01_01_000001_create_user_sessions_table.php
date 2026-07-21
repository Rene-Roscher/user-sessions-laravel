<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('user-sessions.table', 'user_sessions');

        Schema::create($table, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('session_id')->unique();
            $table->string('user_type');
            $table->string('user_id');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('platform', 50)->nullable();
            $table->string('browser', 50)->nullable();
            $table->dateTime('last_activity');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at');

            // Every device-list read is "this user's non-revoked rows", so the morph
            // pair plus revoked_at is the covering index for the package's hot query.
            $table->index(['user_type', 'user_id', 'revoked_at'], 'user_sessions_owner_active_index');

            // Pruning scans last_activity, and separately old revoked rows — an OR over
            // two ranges, so each branch needs its own index.
            $table->index('last_activity');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        $table = config('user-sessions.table', 'user_sessions');

        Schema::dropIfExists($table);
    }
};
