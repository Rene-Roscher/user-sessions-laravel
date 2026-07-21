<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UserSessionsPruned
{
    use Dispatchable;

    public function __construct(
        public int $count,
    ) {}
}
