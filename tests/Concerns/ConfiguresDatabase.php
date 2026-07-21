<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Concerns;

/**
 * Lets the whole suite run against a real database engine, not only SQLite.
 *
 * This matters more than it looks. SQLite silently accepts things MySQL and Postgres
 * reject outright — it does not enforce VARCHAR lengths, does not validate UTF-8, and
 * implements upsert, NOT NULL defaults and index semantics differently. Several defects
 * this package has already fixed (over-long User-Agent, invalid UTF-8, upsert races) are
 * failure modes SQLite structurally *cannot* reproduce, so a green SQLite run says
 * nothing about the databases every production app actually uses.
 */
trait ConfiguresDatabase
{
    protected function configureDatabase(mixed $app): void
    {
        $connection = (string) (env('DB_CONNECTION') ?: 'testing');

        $app['config']->set('database.default', $connection);

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => (string) (env('DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (env('DB_PORT') ?: 33061),
            'database' => (string) (env('DB_DATABASE') ?: 'user_sessions_test'),
            'username' => (string) (env('DB_USERNAME') ?: 'root'),
            'password' => (string) (env('DB_PASSWORD') ?: 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => (string) (env('DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (env('DB_PORT') ?: 54321),
            'database' => (string) (env('DB_DATABASE') ?: 'user_sessions_test'),
            'username' => (string) (env('DB_USERNAME') ?: 'postgres'),
            'password' => (string) (env('DB_PASSWORD') ?: 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ]);
    }
}
