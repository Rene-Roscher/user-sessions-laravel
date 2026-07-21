<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\RevokedBy;

trait HasUserSessions
{
    /**
     * Raw registry rows, newest first. NOT the device list.
     *
     * Use activeSessions() for anything user-facing — this relation includes revoked and
     * store-dead rows, and its last_activity column is debounce-stale (up to sync_interval
     * old). Exposed for admin/audit queries, not for rendering device lists.
     *
     * @return MorphMany<UserSession>
     */
    public function sessions(): MorphMany
    {
        $model = Config::get('user-sessions.model', UserSession::class);

        return $this->morphMany($model, 'user')
            ->orderBy('last_activity', 'desc');
    }

    /**
     * The device list: active sessions with the store's real last activity.
     *
     * Returns the active rows annotated with the session store's authoritative
     * last_activity (Redis TTL / file mtime), not the debounced column. Rows whose
     * sessions the store has already dropped are filtered out via isActive(), so the
     * list reflects reality — the registry rows themselves are left for model:prune.
     *
     * @return Collection<int, UserSession>
     */
    public function activeSessions(): Collection
    {
        return app(ManagesUserSessions::class)->for($this);
    }

    /**
     * Revoke one of this user's sessions by its registry id.
     *
     * Scoped to the user's own rows, so a foreign id is a miss rather than a cross-account
     * revoke. Returns false if no matching row was found.
     */
    public function revokeSession(string $sessionId, ?string $revokedBy = RevokedBy::SELF): bool
    {
        $session = $this->sessions()->whereKey($sessionId)->first();

        if ($session === null) {
            return false;
        }

        return $session->revoke($revokedBy);
    }

    public function currentSession(Request $request): ?UserSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        /** @var class-string<UserSession> $modelClass */
        $modelClass = Config::get('user-sessions.model', UserSession::class);

        return $modelClass::query()
            ->where('session_id', $sessionId)
            ->where('user_type', $this->getMorphClass())
            ->where('user_id', $this->getAuthIdentifier())
            ->first();
    }

    public function revokeOtherSessions(Request $request): int
    {
        if (! $request->hasSession()) {
            return 0;
        }

        return app(ManagesUserSessions::class)
            ->revokeOthers($this, $request->session()->getId());
    }

    public function revokeAllSessions(): int
    {
        return app(ManagesUserSessions::class)->revokeAll($this);
    }
}
