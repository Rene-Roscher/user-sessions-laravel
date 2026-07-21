<?php

declare(strict_types=1);
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\NativeUserAgentParser;
use ReneRoscher\UserSessions\Support\StoreSessionActivityResolver;

return [
    'model' => UserSession::class,
    'parser' => NativeUserAgentParser::class,

    /*
     * Reads last_activity back from the session store instead of trusting the debounced
     * registry column. Redis and file stores can answer exactly when a session was last
     * written; a session the store has already dropped is reported as inactive straight
     * away instead of lingering in device lists until it is pruned. Read path only.
     * Stores that cannot be introspected (memcached, dynamodb, array) fall back to the
     * column automatically.
     */
    'resolve_activity_from_store' => true,
    'activity_resolver' => StoreSessionActivityResolver::class,

    /*
     * Upper bound on how many sessions a device list returns. Nothing prevents a scripted
     * client from logging in over and over and accumulating rows, and rendering that list
     * hydrates every row and asks the session store about each one.
     */
    'max_listed' => 100,

    'table' => 'user_sessions',
    'sync_interval' => 180,
    'sync_on_failure' => false,
    'new_device_window' => 86400,
    'prune_after_days' => 30,

    /*
     * Cache store for the debounce and created markers (null = your default store).
     * This is what keeps the package off the database on the hot path: an "array" or
     * "null" store cannot remember a marker between requests, so the debounce silently
     * degrades into one database write per request. `php artisan about` flags that.
     */
    'cache_store' => null,

    'middleware_group' => 'web',
    'request_macro' => true,
    'revoke_on_password_reset' => true,
    'broadcast' => false,
    'context' => [
        'enabled' => true,
        'keys' => ['device', 'user_session_id'],
    ],
    'events' => true,

    /*
     * While a session is impersonating (an admin logged in as a user), tracking is
     * skipped entirely, so the real user is never sent a "new device" alert for the
     * admin's device. Detected via the native isImpersonated() trait method
     * (lab404/laravel-impersonate) and any of the session keys below. Add your
     * impersonation package's session key here.
     */
    'impersonation' => [
        'session_keys' => ['impersonated_by'],
    ],
];
