<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Listeners;

use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Support\FailureIsolation;
use ReneRoscher\UserSessions\Support\RevokedBy;
use Throwable;

class SyncOtherDeviceLogout
{
    public function __construct(
        private SessionRecorder $recorder,
        private ManagesUserSessions $registry,
    ) {}

    public function handle(OtherDeviceLogout $event): void
    {
        $user = $event->user;

        try {
            // No active session (queue / CLI): we cannot tell which session is "current",
            // so revoke everything rather than silently leaving other devices logged in.
            if (! Session::isStarted()) {
                Log::warning('SyncOtherDeviceLogout: no request context, revoking all sessions for user {id}', ['id' => $user->getAuthIdentifier()]);

                $this->registry->revokeAll($user, RevokedBy::OTHER_DEVICE);

                return;
            }

            $currentSessionId = Session::getId();

            $this->registry->revokeOthers($user, $currentSessionId);
            $this->recorder->forgetOthersFor($user, $currentSessionId);
        } catch (Throwable $e) {
            if (! FailureIsolation::canSwallow($e)) {
                throw $e;
            }

            // logoutOtherDevices() has already rehashed the password by now; throwing
            // would break the caller's flow without re-securing anything. Log loudly —
            // this line means other devices may still hold live sessions.
            Log::error('UserSessions: failed to sync a native logoutOtherDevices() — other sessions may still be active.', [
                'user' => $user->getAuthIdentifier(),
                'exception' => $e,
            ]);
        }
    }
}
