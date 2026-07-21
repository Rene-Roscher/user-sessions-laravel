<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Facades;

use Illuminate\Support\Facades\Facade;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;

/**
 * @method static \Illuminate\Support\Collection for(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \ReneRoscher\UserSessions\Models\UserSession|null find(string $sessionId)
 * @method static bool revoke(\ReneRoscher\UserSessions\Models\UserSession|string $session, ?string $revokedBy = null)
 * @method static int revokeOthers(\Illuminate\Contracts\Auth\Authenticatable $user, string $currentSessionId)
 * @method static int revokeAll(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static array|null payload(\ReneRoscher\UserSessions\Models\UserSession|string $session)
 */
class UserSessions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ManagesUserSessions::class;
    }
}
