<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Database\Factories\UserSessionFactory;
use ReneRoscher\UserSessions\Support\Device;
use ReneRoscher\UserSessions\Support\DeviceType;
use ReneRoscher\UserSessions\Support\RevokedBy;

/**
 * @property string $id
 * @property string $session_id
 * @property string $user_type
 * @property int|string $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property DeviceType|null $device_type
 * @property string|null $platform
 * @property string|null $browser
 * @property CarbonImmutable|null $last_activity
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property-read Device $device
 * @property-read string $device_label
 */
class UserSession extends Model
{
    /** @use HasFactory<UserSessionFactory> */
    use HasFactory;

    use HasUlids;
    use MassPrunable;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * The session store's view of last activity, when it has been resolved.
     *
     * Transient and never persisted: the column stays the debounced registry truth,
     * this holds the store truth for the current read. Null while $storeActivityResolved
     * is true means the store is certain the session no longer exists.
     */
    private ?CarbonImmutable $storeLastActivity = null;

    private bool $storeActivityResolved = false;

    protected function casts(): array
    {
        return [
            'last_activity' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'device_type' => DeviceType::class,
        ];
    }

    /**
     * Honour the configured table name.
     *
     * The migration and the recorder both read `user-sessions.table`; without this the
     * model would keep deriving "user_sessions" from the class name, so renaming the table
     * left every Eloquent path (the facade, the relation, the macro, model:prune) querying
     * a table that does not exist.
     */
    public function getTable(): string
    {
        return $this->table ?? Config::string('user-sessions.table', 'user_sessions');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<UserSession>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $lifetime = Config::integer('session.lifetime', 120);

        $query->whereNull('revoked_at')
            ->where('last_activity', '>', now()->subMinutes($lifetime));
    }

    /**
     * @param  Builder<UserSession>  $query
     */
    public function scopeRevoked(Builder $query): void
    {
        $query->whereNotNull('revoked_at');
    }

    /**
     * @param  Builder<UserSession>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $lifetime = Config::integer('session.lifetime', 120);

        $query->whereNull('revoked_at')
            ->where('last_activity', '<=', now()->subMinutes($lifetime));
    }

    /**
     * Record what the session store said about this session (read path only).
     *
     * @internal Called by the registry; not part of the public API.
     */
    public function setStoreActivity(?CarbonImmutable $lastActivity, bool $resolved = true): void
    {
        $this->storeLastActivity = $lastActivity;
        $this->storeActivityResolved = $resolved;
    }

    /**
     * Best available last-activity timestamp.
     *
     * Prefers the session store (exact) over the registry column (debounced, so up to
     * sync_interval old). Falls back to the column when the store was not consulted or
     * could not answer — and also when the store says the session is gone, so a device
     * list can still show "last seen ...".
     */
    public function lastActivityAt(): ?CarbonImmutable
    {
        if ($this->storeActivityResolved && $this->storeLastActivity !== null) {
            return $this->storeLastActivity;
        }

        return $this->last_activity;
    }

    /**
     * "last active 5 minutes ago" — store-truth where available, the registry column
     * otherwise.
     *
     * Convenience for views: Blade/Livewire/Inertia can call $session->lastActiveHuman()
     * instead of $session->lastActivityAt()?->diffForHumans(). Returns null when no
     * activity is known at all.
     */
    public function lastActiveHuman(): ?string
    {
        return $this->lastActivityAt()?->diffForHumans();
    }

    /**
     * Whether the session still exists in the store: true/false when the store was
     * consulted and answered, null when that is unknown.
     */
    public function existsInStore(): ?bool
    {
        if (! $this->storeActivityResolved) {
            return null;
        }

        return $this->storeLastActivity !== null;
    }

    /**
     * Whether this session can still authenticate a request.
     *
     * A session the store has dropped is dead immediately, no matter what the registry
     * row's last_activity says — that column is only refreshed once per sync_interval
     * and would otherwise keep an expired or regenerated session listed as a live device.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->existsInStore() === false) {
            return false;
        }

        $lifetime = Config::integer('session.lifetime', 120);
        $lastActivity = $this->lastActivityAt();

        return $lastActivity !== null
            && $lastActivity->isAfter(now()->subMinutes($lifetime));
    }

    public function getDeviceAttribute(): Device
    {
        return new Device(
            $this->device_type ?? DeviceType::Unknown,
            $this->platform,
            $this->browser,
        );
    }

    /**
     * "Chrome on macOS" — convenience label for quick display.
     *
     * For i18n or custom formatting, use the raw device_type / platform / browser
     * attributes and build the string in your view or translation file.
     */
    public function getDeviceLabelAttribute(): string
    {
        return $this->device->label();
    }

    public function isCurrent(Request $request): bool
    {
        return $request->hasSession()
            && $this->session_id === $request->session()->getId();
    }

    public function revoke(?string $revokedBy = RevokedBy::SELF): bool
    {
        return app(ManagesUserSessions::class)->revoke($this, revokedBy: $revokedBy);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return app(ManagesUserSessions::class)->payload($this);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $pruneAfterDays = Config::integer('user-sessions.prune_after_days', 30);
        $lifetime = Config::integer('session.lifetime', 120);

        $expiryCutoff = now()->subMinutes($lifetime)->subDays($pruneAfterDays);
        $revokedCutoff = now()->subDays($pruneAfterDays);

        return static::query()
            ->where(function (Builder $q) use ($expiryCutoff, $revokedCutoff): void {
                $q->where('last_activity', '<', $expiryCutoff)
                    ->orWhere(function (Builder $sq) use ($revokedCutoff): void {
                        $sq->whereNotNull('revoked_at')
                            ->where('revoked_at', '<', $revokedCutoff);
                    });
            });
    }

    protected static function newFactory(): UserSessionFactory
    {
        return UserSessionFactory::new();
    }

    /**
     * @param  array<array-key, static>  $models
     * @return UserSessionCollection<array-key, static>
     */
    public function newCollection(array $models = []): UserSessionCollection
    {
        return new UserSessionCollection($models);
    }
}
