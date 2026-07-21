<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface SessionRecorder
{
    public function record(string $sessionId, Authenticatable $user, string $ip, ?string $userAgent): void;

    public function forget(string $sessionId): void;

    public function forgetOthersFor(Authenticatable $user, string $exceptSessionId): void;
}
