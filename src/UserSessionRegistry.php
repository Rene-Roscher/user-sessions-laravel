<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Contracts\SessionActivityResolver;
use ReneRoscher\UserSessions\Events\UserSessionRevoked;
use ReneRoscher\UserSessions\Exceptions\CouldNotRevokeSessions;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\FailureIsolation;
use ReneRoscher\UserSessions\Support\RevokedBy;
use ReneRoscher\UserSessions\Support\SessionPayload;
use RuntimeException;
use Throwable;

final class UserSessionRegistry implements ManagesUserSessions
{
    /**
     * Active sessions of a user, newest activity first.
     *
     * The rows are annotated with the session store's own view of last activity
     * (see resolveActivity()), so a device list never shows a debounce-stale
     * timestamp and never lists a session the store has already dropped.
     *
     * @return Collection<int, UserSession>
     */
    public function for(Authenticatable $user): Collection
    {
        $modelClass = $this->modelClass();

        $sessions = $modelClass::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->orderBy('last_activity', 'desc')
            // Bounded on purpose: this method hydrates every row and asks the store
            // about each one. An unbounded list would be a self-inflicted amplification.
            ->limit(max(1, Config::integer('user-sessions.max_listed', 100)))
            ->get();

        $this->resolveActivity($sessions);

        // Filter AFTER resolving, not with scopeActive() in SQL: a row can be inside the
        // lifetime window by its column while the store has already dropped the session.
        return $sessions->filter(static fn (UserSession $session): bool => $session->isActive())
            ->sortByDesc(fn (UserSession $session): ?int => $session->lastActivityAt()?->getTimestamp())
            ->values();
    }

    /**
     * Annotate a set of rows with the session store's authoritative activity.
     *
     * This is the read-path counterpart to the debounced write path: the registry
     * row may lag by up to sync_interval, but the store knows exactly when the
     * session was last written — and whether it still exists at all.
     *
     * @param  iterable<UserSession>  $sessions
     */
    public function resolveActivity(iterable $sessions): void
    {
        if (! Config::boolean('user-sessions.resolve_activity_from_store', true)) {
            return;
        }

        /** @var array<int, UserSession> $rows */
        $rows = [];

        foreach ($sessions as $session) {
            $rows[] = $session;
        }

        if ($rows === []) {
            return;
        }

        $resolver = app(SessionActivityResolver::class);

        if (! $resolver->supported()) {
            return;
        }

        $activity = $resolver->resolve(array_map(
            static fn (UserSession $session): string => $session->session_id,
            $rows,
        ));

        $currentSessionId = Session::isStarted() ? Session::getId() : null;

        foreach ($rows as $session) {
            // The request's own session is alive by definition — and the store may not
            // know about it yet, because StartSession only writes it once the response is
            // finished. Trusting the store here would mark the caller's own device dead.
            if ($currentSessionId !== null && $session->session_id === $currentSessionId) {
                $session->setStoreActivity(CarbonImmutable::now(), resolved: true);

                continue;
            }

            if (array_key_exists($session->session_id, $activity)) {
                $session->setStoreActivity($activity[$session->session_id], resolved: true);
            }
        }
    }

    /**
     * Drop registry rows the session store no longer knows about.
     *
     * The registry is a derivative and may drift (a session id regenerated mid-flight,
     * a store flushed out of band). This reconciles it on demand — never on the hot
     * path. Returns the number of rows marked revoked.
     */
    public function reconcile(Authenticatable $user): int
    {
        $resolver = app(SessionActivityResolver::class);

        if (! $resolver->supported()) {
            return 0;
        }

        $modelClass = $this->modelClass();

        $sessions = $modelClass::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->get();

        if ($sessions->isEmpty()) {
            return 0;
        }

        $activity = $resolver->resolve(
            $sessions->map(static fn (UserSession $session): string => $session->session_id)->values()->all(),
        );

        $count = 0;

        $currentSessionId = Session::isStarted() ? Session::getId() : null;

        foreach ($sessions as $session) {
            // Never reconcile away the caller's own session (see resolveActivity()).
            if ($session->session_id === $currentSessionId) {
                continue;
            }

            // Present in the map with a null value = the store is certain it is gone.
            if (array_key_exists($session->session_id, $activity) && $activity[$session->session_id] === null) {
                $session->forceFill(['revoked_at' => now()])->save();

                // Not an admin action — the session expired or its id was rotated, and the
                // registry is only now catching up. Saying so lets a listener tell the
                // difference between "we ended this" and "it ended on its own".
                $this->dispatchRevoked($session, RevokedBy::EXPIRED);

                $count++;
            }
        }

        return $count;
    }

    public function find(string $sessionId): ?UserSession
    {
        $modelClass = $this->modelClass();

        /** @var UserSession|null $session */
        $session = $modelClass::query()->where('session_id', $sessionId)->first();

        return $session;
    }

    public function revoke(UserSession|string $session, ?string $revokedBy = null): bool
    {
        if (is_string($session)) {
            $session = $this->find($session);

            if ($session === null) {
                return false;
            }
        }

        if ($session->revoked_at !== null) {
            return false;
        }

        $this->warnIfOutsideRequestContext();

        $this->revokeRow($session, $revokedBy);

        return true;
    }

    public function revokeOthers(Authenticatable $user, string $currentSessionId, ?string $revokedBy = RevokedBy::OTHER_DEVICE): int
    {
        $modelClass = $this->modelClass();

        $sessions = $modelClass::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->where('session_id', '!=', $currentSessionId)
            ->get();

        return $this->revokeEach($sessions, $revokedBy);
    }

    public function revokeAll(Authenticatable $user, ?string $revokedBy = RevokedBy::ADMIN): int
    {
        $modelClass = $this->modelClass();

        $sessions = $modelClass::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->get();

        return $this->revokeEach($sessions, $revokedBy);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(UserSession|string $session): ?array
    {
        if (is_string($session)) {
            $session = $this->find($session);

            if ($session === null) {
                return null;
            }
        }

        // Deliberately NOT gated on Session::isStarted(): reading a payload only needs
        // the handler, which the SessionManager builds fine in console/queue context —
        // and tinker is exactly where this is most useful.
        $store = Session::driver();

        if (! $store instanceof Store) {
            return null;
        }

        return SessionPayload::forSession($store)->read($session->session_id);
    }

    /**
     * @param  Collection<int, UserSession>  $sessions
     */
    private function revokeEach(Collection $sessions, ?string $revokedBy): int
    {
        if ($sessions->isEmpty()) {
            return 0;
        }

        $this->warnIfOutsideRequestContext();

        $count = 0;
        $failed = [];

        foreach ($sessions as $session) {
            try {
                $this->revokeRow($session, $revokedBy);

                $count++;
            } catch (Throwable $e) {
                // Never abort the loop. "Log out everywhere else" is a security control:
                // one deadlocked row must not leave the remaining devices authenticated.
                // Inside a transaction there is nothing left to salvage though — every
                // following statement would fail anyway on Postgres — so surface it.
                if (! FailureIsolation::canSwallow($e)) {
                    throw $e;
                }

                $failed[$session->session_id] = $e;
            }
        }

        if ($failed !== []) {
            // Every session was attempted, but the caller must not be told this succeeded —
            // otherwise a controller renders "you have been logged out everywhere" while
            // live sessions remain. Listeners catch this and log it without breaking the
            // user's flow; direct callers get to surface it.
            throw new CouldNotRevokeSessions($failed, $count);
        }

        return $count;
    }

    /**
     * End one session: store first, bookkeeping second.
     *
     * The order matters — if persisting revoked_at fails, the session is already dead
     * in the store, which is the safe direction to fail in.
     */
    private function revokeRow(UserSession $session, ?string $revokedBy): void
    {
        if ($this->isCurrentSession($session->session_id)) {
            $this->revokeCurrentSession();
        } else {
            $this->destroyStoredSession($session->session_id);
        }

        // A false return means an application "saving" listener vetoed the write. The
        // store session is already destroyed at this point, so staying quiet would leave
        // the registry claiming the session is live while it is not — and, for a bulk
        // revoke, would count it as successfully ended.
        if ($session->forceFill(['revoked_at' => now()])->save() === false) {
            throw new RuntimeException("Could not persist revoked_at for session [{$session->session_id}].");
        }

        $this->dispatchRevoked($session, $revokedBy);
    }

    private function dispatchRevoked(UserSession $session, ?string $revokedBy): void
    {
        if (Config::boolean('user-sessions.events', true)) {
            event(new UserSessionRevoked($session, $revokedBy));
        }
    }

    private function revokeCurrentSession(): void
    {
        // The target is the current request's own session — flush + regenerate so
        // StartSession cannot write the in-memory payload back and resurrect it.
        Session::invalidate();

        // The session ID was just regenerated; the guard still holds the user in memory,
        // so tell the middleware NOT to record this regenerated (now-revoked) session as a
        // fresh active row on the way out of the request.
        request()->attributes->set(RecordSessions::SKIP_RECORDING, true);
    }

    /**
     * Destroy a session in the store, driver-agnostically.
     *
     * Runs in EVERY context. The SessionManager builds the handler from config without
     * needing a started session, so an admin revoke from a command, tinker or a queued
     * job really does end the session — gating this on Session::isStarted() used to make
     * those revokes a silent no-op that still reported success.
     */
    private function destroyStoredSession(string $sessionId): void
    {
        Session::getHandler()->destroy($sessionId);
    }

    /**
     * Whether the given session is the one bound to the current request.
     *
     * Uses Session::isStarted() rather than runningInConsole(): under Octane/Swoole
     * PHP_SAPI is still 'cli', so runningInConsole() would wrongly report true during
     * an HTTP request and defeat the resurrection guard.
     */
    private function isCurrentSession(string $sessionId): bool
    {
        return Session::isStarted() && Session::getId() === $sessionId;
    }

    /**
     * Outside a request there is no "current" session, so the resurrection guard cannot
     * run. Sessions are still destroyed store-side; we just record that the distinction
     * was unavailable (PLAN §9.4).
     */
    private function warnIfOutsideRequestContext(): void
    {
        if (! Session::isStarted()) {
            Log::warning('UserSessions: revoking outside request context — store destroy runs, current-session detection is unavailable.');
        }
    }

    /**
     * @return class-string<UserSession>
     */
    private function modelClass(): string
    {
        /** @var class-string<UserSession> $model */
        $model = Config::string('user-sessions.model', UserSession::class);

        return $model;
    }
}
