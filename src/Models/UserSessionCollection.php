<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;

/**
 * @template TKey of array-key
 * @template TModel of UserSession
 *
 * @extends EloquentCollection<TKey, TModel>
 */
class UserSessionCollection extends EloquentCollection
{
    /**
     * Annotate every row with the session store's authoritative last activity.
     *
     * Use this whenever you render a device list from the relation
     * ($user->sessions->withStoreActivity()) — UserSessions::for($user) already does it.
     * Costs one batched store round trip, never a database write.
     */
    public function withStoreActivity(): static
    {
        app(ManagesUserSessions::class)->resolveActivity($this);

        return $this;
    }
}
