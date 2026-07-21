<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

enum DeviceType: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Bot = 'bot';
    case Unknown = 'unknown';
}
