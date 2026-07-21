<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\RevokedBy;

interface ManagesUserSessions
{
    /**
     * Active sessions of a user, annotated with the store's real last activity.
     *
     * @return Collection<int, UserSession>
     */
    public function for(Authenticatable $user): Collection;

    public function find(string $sessionId): ?UserSession;

    public function revoke(UserSession|string $session, ?string $revokedBy = null): bool;

    public function revokeOthers(Authenticatable $user, string $currentSessionId, ?string $revokedBy = RevokedBy::OTHER_DEVICE): int;

    public function revokeAll(Authenticatable $user, ?string $revokedBy = RevokedBy::ADMIN): int;

    /**
     * Annotate rows with the session store's authoritative last activity (read path only).
     *
     * @param  iterable<UserSession>  $sessions
     */
    public function resolveActivity(iterable $sessions): void;

    /**
     * Mark rows revoked whose sessions the store no longer holds. Returns the count.
     */
    public function reconcile(Authenticatable $user): int;

    /**
     * @return array<string, mixed>|null
     */
    public function payload(UserSession|string $session): ?array;
}
