<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use ReneRoscher\UserSessions\Models\UserSession;
use Throwable;

/**
 * Decides whether a registry failure may be logged and swallowed.
 *
 * The registry is a derivative, so a failed bookkeeping write must not break a user's
 * logout or password reset — but that reasoning only holds *outside* a transaction.
 * PostgreSQL marks an entire transaction as aborted after any failed statement
 * (SQLSTATE 25P02), so swallowing an error inside one does not keep the caller working:
 * it merely replaces the real exception with a stream of confusing "current transaction
 * is aborted" errors further down the call stack. There, rethrowing is strictly kinder.
 */
final class FailureIsolation
{
    /**
     * Whether an already-thrown registry error can be safely contained.
     *
     * Only a database error inside a transaction is un-swallowable — anything else (a
     * vetoed save, a broken listener) leaves the connection perfectly usable and is
     * exactly the kind of failure the registry is allowed to absorb.
     */
    public static function canSwallow(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return true;
        }

        return DB::connection(self::registryConnection())->transactionLevel() === 0;
    }

    /**
     * The connection the registry model lives on — checking the default connection would
     * wrongly rethrow for apps that put the registry on a connection of its own.
     */
    private static function registryConnection(): ?string
    {
        /** @var class-string<UserSession> $model */
        $model = Config::string('user-sessions.model', UserSession::class);

        return (new $model)->getConnectionName();
    }
}
