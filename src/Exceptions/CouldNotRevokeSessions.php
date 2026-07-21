<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a bulk revoke could not end every session it was asked to.
 *
 * Bulk revocation is a security control, so partial failure must be loud: the alternative
 * is a controller cheerfully rendering "you have been logged out everywhere" while some
 * devices are still authenticated. Every session is still attempted before this is thrown.
 */
final class CouldNotRevokeSessions extends RuntimeException
{
    /**
     * @param  array<string, Throwable>  $failures  keyed by session id
     */
    public function __construct(
        public readonly array $failures,
        public readonly int $revoked,
    ) {
        parent::__construct(sprintf(
            'Revoked %d session(s), but %d could not be ended: %s',
            $revoked,
            count($failures),
            implode(', ', array_keys($failures)),
        ), previous: reset($failures) ?: null);
    }
}
