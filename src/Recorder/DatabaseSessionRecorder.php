<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Recorder;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Contracts\UserAgentParser;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\Device;

final class DatabaseSessionRecorder implements SessionRecorder
{
    /** Column widths from the shipped migration; over-long values are truncated, never dropped. */
    private const MAX_USER_AGENT = 500;

    private const MAX_PLATFORM = 50;

    private const MAX_BROWSER = 50;

    public function __construct(
        private UserAgentParser $parser,
    ) {}

    public function record(string $sessionId, Authenticatable $user, string $ip, ?string $userAgent): void
    {
        $interval = Config::integer('user-sessions.sync_interval', 180);
        $table = Config::string('user-sessions.table', 'user_sessions');

        if ($interval > 0 && ! $this->cache()->add($this->debounceKey($sessionId), 1, $interval)) {
            return;
        }

        $device = $this->parser->parse($userAgent);

        $createdTtl = max(Config::integer('session.lifetime', 120) * 60, $interval, 60);
        $createdKey = $this->createdKey($sessionId);

        $now = Carbon::now();

        $row = [
            // Lowercased to match Eloquent's HasUlids, so every row in the table looks
            // the same whether it was written here or through the model.
            'id' => strtolower((string) Str::ulid()),
            'session_id' => $sessionId,
            'user_id' => $user->getAuthIdentifier(),
            'user_type' => $user->getMorphClass(),
            'ip_address' => $ip,
            // Truncated to the column width: MySQL/Postgres reject over-long values and
            // abort the upsert, which would permanently stop recording that session.
            'user_agent' => $this->clean($userAgent, self::MAX_USER_AGENT),
            'device_type' => $device->type->value,
            'platform' => $this->clean($device->platform, self::MAX_PLATFORM),
            'browser' => $this->clean($device->browser, self::MAX_BROWSER),
            'last_activity' => $now,
            'created_at' => $now,
        ];

        DB::table($table)->upsert(
            [$row],
            uniqueBy: ['session_id'],
            update: ['ip_address', 'last_activity', 'user_id', 'user_type', 'device_type', 'platform', 'browser', 'user_agent'],
        );

        // "Announced" marker: fire the created event once per session. Claimed AFTER
        // the write succeeds, so a single failed upsert does not permanently swallow
        // the new-device alert. The TTL is tied to the session lifetime and refreshed
        // on every write, so a continuously-active session never re-announces.
        $isNew = $this->cache()->add($createdKey, 1, $createdTtl);

        if (! $isNew) {
            $this->cache()->put($createdKey, 1, $createdTtl);
        }

        if ($isNew && Config::boolean('user-sessions.events', true)) {
            $modelClass = $this->modelClass();
            $session = $modelClass::query()->where('session_id', $sessionId)->first();

            if ($session !== null) {
                Event::dispatch(new UserSessionCreated(
                    $session,
                    isNewDevice: $this->isNewDevice($user, $device, $sessionId),
                ));
            }
        }
    }

    public function forget(string $sessionId): void
    {
        $table = Config::string('user-sessions.table', 'user_sessions');

        DB::table($table)->where('session_id', $sessionId)->delete();
    }

    public function forgetOthersFor(Authenticatable $user, string $exceptSessionId): void
    {
        $table = Config::string('user-sessions.table', 'user_sessions');

        DB::table($table)
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('session_id', '!=', $exceptSessionId)
            ->delete();
    }

    private function isNewDevice(Authenticatable $user, Device $device, string $exceptSessionId): bool
    {
        $window = Config::integer('user-sessions.new_device_window', 86400);
        $modelClass = $this->modelClass();

        $existing = $modelClass::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('session_id', '!=', $exceptSessionId)
            ->where('device_type', $device->type->value)
            ->where('platform', $device->platform)
            ->where('browser', $device->browser)
            ->whereNull('revoked_at')
            ->where('last_activity', '>', now()->subSeconds($window))
            ->exists();

        return ! $existing;
    }

    /**
     * The cache store holding the debounce and created markers.
     *
     * Configurable because this decides whether the package keeps its central promise:
     * on an "array"/"null" store Cache::add() never sees a previous marker, so the
     * debounce silently degrades to a database write on every single request. Point
     * this at redis/memcached/database in production.
     */
    private function cache(): Repository
    {
        $store = Config::get('user-sessions.cache_store');

        return Cache::store(is_string($store) ? $store : null);
    }

    /**
     * Make a header value safe to store: valid UTF-8 first, then within the column width.
     *
     * A User-Agent is arbitrary client bytes. A malformed sequence makes MySQL reject
     * the row with "Incorrect string value" and Postgres with "invalid byte sequence",
     * aborting the upsert. The debounce marker is already claimed at that point, so
     * the exception (swallowed inside defer()) would permanently stop recording that
     * session.
     */
    private function clean(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            // Drop invalid sequences rather than the whole value; a mangled label is
            // better than a session that can never be recorded again.
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // No control characters belong in a device label; CR/LF are a log-injection risk.
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function debounceKey(string $sessionId): string
    {
        return 'user-sessions:debounce:'.$sessionId;
    }

    private function createdKey(string $sessionId): string
    {
        return 'user-sessions:created:'.$sessionId;
    }

    /**
     * @return class-string<UserSession>
     */
    private function modelClass(): string
    {
        /** @var class-string<UserSession> $model */
        $model = Config::string('user-sessions.model', UserSession::class);

        return $model;
    }
}
