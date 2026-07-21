<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresDatabase;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;
use ReneRoscher\UserSessions\UserSessionsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use ConfiguresDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            UserSessionsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('session.encrypt', false);
        $app['config']->set('session.lifetime', 120);

        $this->configureDatabase($app);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.web.provider', 'users');

        $app['config']->set('user-sessions.middleware_group', 'web');
        $app['config']->set('user-sessions.sync_interval', 0);
        $app['config']->set('user-sessions.events', true);
    }
}
