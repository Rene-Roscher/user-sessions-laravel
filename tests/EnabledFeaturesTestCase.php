<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresDatabase;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresSessionDriver;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;
use ReneRoscher\UserSessions\UserSessionsServiceProvider;

/**
 * Boots the package with every opt-in feature turned ON at registration time, so the
 * boot-time wiring (middleware group, notification listener, broadcasting) can be
 * exercised end-to-end.
 */
abstract class EnabledFeaturesTestCase extends BaseTestCase
{
    use ConfiguresDatabase;
    use ConfiguresSessionDriver;
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            UserSessionsServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $this->configureSessionDriver($app);

        $this->configureDatabase($app);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.web.provider', 'users');

        $app['config']->set('user-sessions.middleware_group', 'web');
        $app['config']->set('user-sessions.sync_interval', 0);
        $app['config']->set('user-sessions.events', true);
        $app['config']->set('user-sessions.broadcast', true);
    }

    protected function defineRoutes($router): void
    {
        // The route carries no explicit *package* middleware — RecordSessions reaches it
        // only because the provider pushed it into the 'web' group. StartSession is added
        // because Testbench's bare 'web' group does not include it.
        $router->get('/web-home', fn () => response('OK'))
            ->middleware(['web', StartSession::class]);
    }

    protected function tearDown(): void
    {
        $this->flushSessionDriverState();

        parent::tearDown();

        $this->removeSessionFilePath();
    }
}
