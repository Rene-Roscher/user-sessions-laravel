<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Support\FailureIsolation;
use ReneRoscher\UserSessions\Support\RevokedBy;
use Throwable;

class RevokeOnPasswordReset
{
    public function __construct(
        private ManagesUserSessions $registry,
    ) {}

    public function handle(PasswordReset $event): void
    {
        if (! Config::boolean('user-sessions.revoke_on_password_reset', true)) {
            return;
        }

        $user = $event->user;

        try {
            if (Session::isStarted()) {
                $this->registry->revokeOthers($user, Session::getId(), RevokedBy::PASSWORD_RESET);

                return;
            }

            // Password reset via e-mail link has no current session — revoke everything.
            Log::info('RevokeOnPasswordReset: no current session, revoking all sessions for user {id}', ['id' => $user->getAuthIdentifier()]);

            $this->registry->revokeAll($user, RevokedBy::PASSWORD_RESET);
        } catch (Throwable $e) {
            if (! FailureIsolation::canSwallow($e)) {
                throw $e;
            }

            // The password has already been changed by the time this fires, so throwing
            // would only hand the user an error page without making them any safer.
            // Fail loudly in the log instead — this line means sessions may still be live.
            Log::error('UserSessions: failed to revoke sessions after a password reset — other sessions may still be active.', [
                'user' => $user->getAuthIdentifier(),
                'exception' => $e,
            ]);
        }
    }
}
