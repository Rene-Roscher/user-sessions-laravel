<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Contracts;

use ReneRoscher\UserSessions\Support\Device;

interface UserAgentParser
{
    public function parse(?string $userAgent): Device;
}
