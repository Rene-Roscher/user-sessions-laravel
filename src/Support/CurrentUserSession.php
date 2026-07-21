<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReneRoscher\UserSessions\Models\UserSession;

/**
 * Resolves (and memoizes) the registry row for the current request's session.
 *
 * Shared by the userSession() request macro and the context sharer so both hit the
 * same per-request memo — the context sharer must never trigger a query of its own.
 */
final class CurrentUserSession
{
    /**
     * Memo lives in the request's attribute bag, which is Octane-safe: every request
     * gets a fresh Request instance and therefore a fresh bag.
     */
    public const ATTRIBUTE = '__user_sessions.current';

    /**
     * @param  bool  $memoized  When true, never query — return null unless already resolved.
     */
    public static function resolve(Request $request, bool $memoized = false): ?UserSession
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return null;
        }

        if ($request->attributes->has(self::ATTRIBUTE)) {
            /** @var UserSession|null $cached */
            $cached = $request->attributes->get(self::ATTRIBUTE);

            return $cached;
        }

        if ($memoized) {
            return null;
        }

        /** @var class-string<UserSession> $modelClass */
        $modelClass = Config::string('user-sessions.model', UserSession::class);

        /** @var UserSession|null $session */
        $session = $modelClass::query()
            ->where('session_id', $request->session()->getId())
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        $request->attributes->set(self::ATTRIBUTE, $session);

        return $session;
    }
}
