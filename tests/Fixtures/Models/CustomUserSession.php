<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Fixtures\Models;

use ReneRoscher\UserSessions\Models\UserSession;

/**
 * A consumer's custom session model bound via config('user-sessions.model'). Uses the same
 * table so the recorder's created-event lookup must resolve it from config, not hard-code the base.
 */
class CustomUserSession extends UserSession
{
    protected $table = 'user_sessions';
}
