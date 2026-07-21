<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Concerns;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Lets the whole suite run against a session driver chosen by the environment.
 *
 * Without this the package's "works with any driver" claim is only ever exercised
 * against "array", which is the one driver that cannot expire, cannot be introspected
 * and never leaves the process — i.e. the one least like production. CI sets
 * SESSION_DRIVER to array, file and redis (phpredis and predis) in turn.
 */
trait ConfiguresSessionDriver
{
    protected ?string $sessionFilePath = null;

    protected function configureSessionDriver(mixed $app): void
    {
        $driver = (string) (env('SESSION_DRIVER') ?: 'array');

        $app['config']->set('session.driver', $driver);
        $app['config']->set('session.encrypt', false);
        $app['config']->set('session.lifetime', 120);

        if ($driver === 'file') {
            $this->sessionFilePath = sys_get_temp_dir().'/user-sessions-suite-'.bin2hex(random_bytes(6));

            if (! is_dir($this->sessionFilePath)) {
                mkdir($this->sessionFilePath, 0777, true);
            }

            $app['config']->set('session.files', $this->sessionFilePath);
        }

        $app['config']->set('database.redis.client', (string) (env('REDIS_CLIENT') ?: 'phpredis'));
        $app['config']->set('database.redis.default', [
            'host' => (string) (env('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int) (env('REDIS_PORT') ?: 6379),
            // A dedicated database so a stray flush never touches anything real.
            'database' => 14,
        ]);

        if ($driver === 'redis') {
            $app['config']->set('cache.default', 'redis');
        }
    }

    /**
     * Redis keeps state between tests, unlike a fresh array store or :memory: sqlite.
     */
    protected function flushSessionDriverState(): void
    {
        if (config('session.driver') === 'redis') {
            try {
                Redis::connection()->flushdb();
            } catch (Throwable) {
                // A missing redis server is reported by the tests that need it.
            }
        }

        if ($this->sessionFilePath !== null && is_dir($this->sessionFilePath)) {
            array_map(unlink(...), glob($this->sessionFilePath.'/*') ?: []);
        }
    }

    protected function removeSessionFilePath(): void
    {
        if ($this->sessionFilePath !== null && is_dir($this->sessionFilePath)) {
            array_map(unlink(...), glob($this->sessionFilePath.'/*') ?: []);
            rmdir($this->sessionFilePath);
            $this->sessionFilePath = null;
        }
    }
}
