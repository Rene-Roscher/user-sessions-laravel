<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresDatabase;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresSessionDriver;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;
use ReneRoscher\UserSessions\UserSessionsServiceProvider;

/**
 * Boots the package with the opt-in features turned OFF at registration time.
 *
 * Toggles that are only read at boot cannot be tested by flipping config afterwards —
 * the wiring has already happened. This is the "off" half that PLAN §12 test 15 demands
 * for every switch.
 */
abstract class DisabledFeaturesTestCase extends BaseTestCase
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
        // Macros live in a static property on the Macroable trait, so one earlier test
        // registering userSession() would leave it registered for the whole process and
        // make "not registered" unfalsifiable. Clear it before the provider boots; the
        // provider re-registers it in every other test case that has the toggle on.
        Request::flushMacros();

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $this->configureSessionDriver($app);

        $this->configureDatabase($app);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.web.provider', 'users');

        $app['config']->set('user-sessions.sync_interval', 0);
        $app['config']->set('user-sessions.request_macro', false);
        $app['config']->set('user-sessions.revoke_on_password_reset', false);
        $app['config']->set('user-sessions.middleware_group', null);
        $app['config']->set('user-sessions.broadcast', false);
    }

    protected function tearDown(): void
    {
        $this->flushSessionDriverState();

        parent::tearDown();

        $this->removeSessionFilePath();
    }
}
