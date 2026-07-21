<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Contracts\SessionActivityResolver;
use SessionHandlerInterface;
use Throwable;

/**
 * Resolves last activity straight from the configured session store.
 *
 * Redis (and any cache-backed driver on a Redis store): Laravel's
 * CacheBasedSessionHandler::write() calls put($id, $data, lifetime) on every single
 * request, so the entry's TTL is reset each time. The remaining TTL therefore encodes
 * exactly how long ago the session was last written:
 *
 *     lastActivity = now - (lifetime - remainingTtl)
 *
 * File: the session file's mtime is the last write.
 *
 * Anything else (memcached, dynamodb, array, database) has no cheap introspection —
 * supported() reports false and callers keep the registry's persisted value.
 */
final class StoreSessionActivityResolver implements SessionActivityResolver
{
    public function supported(): bool
    {
        try {
            $handler = $this->handler();
        } catch (Throwable) {
            return false;
        }

        if ($handler instanceof FileSessionHandler) {
            return true;
        }

        return $this->redisStore($handler) !== null;
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @return array<string, CarbonImmutable|null>
     */
    public function resolve(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        try {
            $handler = $this->handler();

            if ($handler instanceof FileSessionHandler) {
                return $this->resolveFromFiles($sessionIds);
            }

            $store = $this->redisStore($handler);

            if ($store !== null) {
                return $this->resolveFromRedis($store, $sessionIds);
            }
        } catch (Throwable) {
            // The store being unreachable must never break a device list; the caller
            // falls back to the registry's own timestamps.
            return [];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @return array<string, CarbonImmutable|null>
     */
    private function resolveFromRedis(RedisStore $store, array $sessionIds): array
    {
        $lifetime = $this->lifetimeSeconds();
        $prefix = $store->getPrefix();
        $connection = $store->connection();

        $ttls = $this->readTtls($connection, $prefix, $sessionIds);

        $resolved = [];

        foreach ($sessionIds as $index => $sessionId) {
            $ttl = $ttls[$index] ?? null;

            if (! is_int($ttl)) {
                continue;
            }

            // -2: no such key — the store is certain this session is gone.
            if ($ttl === -2) {
                $resolved[$sessionId] = null;

                continue;
            }

            // -1: key exists but carries no expiry, so elapsed time is unknowable.
            if ($ttl < 0) {
                continue;
            }

            // A TTL larger than the configured lifetime means the entry was written while
            // a longer session.lifetime was in force. The elapsed time is then unknowable
            // (we do not know the old lifetime), so report "present but unknown" and let
            // the caller keep the registry column rather than inventing "active just now".
            // Self-corrects on that session's next request, which resets the TTL.
            if ($ttl > $lifetime) {
                continue;
            }

            $resolved[$sessionId] = $this->activityFromTtl($ttl, $lifetime);
        }

        return $resolved;
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @return array<string, CarbonImmutable|null>
     */
    private function resolveFromFiles(array $sessionIds): array
    {
        $path = rtrim(Config::string('session.files', ''), '/');

        if ($path === '') {
            return [];
        }

        $resolved = [];

        foreach ($sessionIds as $sessionId) {
            // Session ids come from our own registry, but never let one walk the tree.
            if ($sessionId === '' || str_contains($sessionId, '/') || str_contains($sessionId, '\\') || str_contains($sessionId, '..')) {
                continue;
            }

            $file = $path.'/'.$sessionId;

            if (! is_file($file)) {
                $resolved[$sessionId] = null;

                continue;
            }

            $mtime = @filemtime($file);

            if ($mtime === false) {
                continue;
            }

            $resolved[$sessionId] = CarbonImmutable::createFromTimestamp($mtime);
        }

        return $resolved;
    }

    /**
     * Read the remaining TTL for each session.
     *
     * Deliberately one command per key rather than a pipeline: pipelining hands the
     * callback a raw client object whose type differs between phpredis, predis and
     * cluster connections, which cannot be verified statically and would have to be
     * papered over. The cost is bounded instead — `max_listed` caps how many sessions a
     * device list resolves, and that page is rendered rarely, never on the hot path.
     *
     * @param  array<int, string>  $sessionIds
     * @return array<int, mixed>
     */
    private function readTtls(Connection $connection, string $prefix, array $sessionIds): array
    {
        return array_map(
            static fn (string $sessionId): mixed => $connection->ttl($prefix.$sessionId),
            $sessionIds,
        );
    }

    /**
     * Convert a remaining TTL into the moment the session was last written.
     *
     * Clamped at both ends: a TTL larger than the configured lifetime (lifetime was
     * lowered after the session was written, or clock skew) must never produce a
     * timestamp in the future.
     */
    private function activityFromTtl(int $ttl, int $lifetime): CarbonImmutable
    {
        $elapsed = max(0, $lifetime - $ttl);

        return CarbonImmutable::now()->subSeconds($elapsed);
    }

    private function lifetimeSeconds(): int
    {
        return max(1, Config::integer('session.lifetime', 120)) * 60;
    }

    private function handler(): SessionHandlerInterface
    {
        return Session::getHandler();
    }

    private function redisStore(SessionHandlerInterface $handler): ?RedisStore
    {
        if (! $handler instanceof CacheBasedSessionHandler) {
            return null;
        }

        $store = $handler->getCache()->getStore();

        return $store instanceof RedisStore ? $store : null;
    }
}
