<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresDatabase;
use ReneRoscher\UserSessions\Tests\Concerns\ConfiguresSessionDriver;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;
use ReneRoscher\UserSessions\UserSessionsServiceProvider;

abstract class FeatureTestCase extends BaseTestCase
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

    public function defineDatabaseMigrationsUsingPest(): void
    {
        $this->defineDatabaseMigrations();
    }

    public function destroyDatabaseMigrationsUsingPest(): void
    {
        // Cleanup handled by RefreshDatabase / Testbench
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $this->configureSessionDriver($app);

        $this->configureDatabase($app);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.web.provider', 'users');

        $app['config']->set('user-sessions.middleware_group', null);
        $app['config']->set('user-sessions.sync_interval', 0);
        $app['config']->set('user-sessions.events', true);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/', fn () => response('OK'))
            ->middleware(['web', RecordSessions::class]);
        $router->post('/login', function (Request $request) {
            $credentials = $request->only('email', 'password');

            if (Auth::attempt($credentials)) {
                $request->session()->regenerate();

                return response('OK');
            }

            return response('Unauthorized', 401);
        })->middleware(['web', RecordSessions::class]);
        $router->post('/logout', function (Request $request) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response('OK');
        })->middleware(['web', RecordSessions::class]);
        $router->get('/protected', fn () => response('OK'))->middleware(['web', 'auth', RecordSessions::class]);
    }

    protected function tearDown(): void
    {
        $this->flushSessionDriverState();

        parent::tearDown();

        $this->removeSessionFilePath();
    }
}
