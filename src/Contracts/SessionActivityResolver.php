<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Contracts;

use Carbon\CarbonImmutable;

/**
 * Reads the session store's own view of when a session was last touched.
 *
 * The registry table is written debounced (once per sync_interval), so its
 * last_activity lags by design and it keeps listing sessions the store has already
 * expired. The store itself knows better: cache-backed drivers refresh the entry's
 * TTL on every write, so the remaining TTL encodes the time since the last request.
 *
 * Resolvers are read-path only. Nothing here may run on the hot path.
 */
interface SessionActivityResolver
{
    /**
     * Whether this resolver can introspect the configured session store at all.
     *
     * False for stores with no TTL/mtime introspection (memcached, dynamodb, array),
     * in which case callers fall back to the persisted last_activity column.
     */
    public function supported(): bool;

    /**
     * Resolve last-activity timestamps for the given session ids.
     *
     * A key is present in the result only when the store gave a definite answer:
     *   - CarbonImmutable => the session exists and was last written then
     *   - null            => the store is certain the session is gone (expired/destroyed)
     * Ids the store could not answer for are omitted entirely, so callers keep
     * whatever the registry row says.
     *
     * @param  array<int, string>  $sessionIds
     * @return array<string, CarbonImmutable|null>
     */
    public function resolve(array $sessionIds): array;
}
