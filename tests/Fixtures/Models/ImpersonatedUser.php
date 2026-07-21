<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Fixtures\Models;

/**
 * Mimics a model using an impersonation trait (e.g. lab404/laravel-impersonate),
 * which exposes isImpersonated() returning true while impersonated.
 */
class ImpersonatedUser extends User
{
    protected $table = 'users';

    public function isImpersonated(): bool
    {
        return true;
    }
}
