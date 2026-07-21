<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Listeners;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Support\FailureIsolation;
use Throwable;

class ForgetSessionOnLogout
{
    public function __construct(
        private SessionRecorder $recorder,
    ) {}

    public function handle(Logout|CurrentDeviceLogout $event): void
    {
        if (! session()->isStarted()) {
            return;
        }

        $sessionId = session()->getId();

        if ($sessionId === '') {
            return;
        }

        try {
            $this->recorder->forget($sessionId);
        } catch (Throwable $e) {
            // Pure bookkeeping: the framework ends this session either way, and the
            // registry is a derivative that may lag. A database hiccup must not turn a
            // logout into a 500 — pruning cleans the stale row up later. Inside a
            // transaction it must still propagate (see FailureIsolation).
            if (! FailureIsolation::canSwallow($e)) {
                throw $e;
            }

            Log::error('UserSessions: failed to remove the registry row on logout.', ['exception' => $e]);
        }
    }
}
