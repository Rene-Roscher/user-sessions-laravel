<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

/**
 * Reasons carried by UserSessionRevoked::$revokedBy.
 *
 * Kept as string constants rather than an enum so the event payload stays a plain
 * ?string — applications may dispatch their own reasons without extending an enum.
 */
final class RevokedBy
{
    /** The user ended this session themselves (device list, "log out"). */
    public const SELF = 'self';

    /** Ended as part of "log out everywhere else" / native logoutOtherDevices(). */
    public const OTHER_DEVICE = 'other-device';

    /** Ended because the account password was reset. */
    public const PASSWORD_RESET = 'password-reset';

    /** Ended from the admin/service layer (facade, command, Nova action). */
    public const ADMIN = 'admin';

    /** The store no longer held the session; reconcile() caught up with reality. */
    public const EXPIRED = 'expired';
}
