<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;
use ReneRoscher\UserSessions\Context\ShareSessionContext;
use ReneRoscher\UserSessions\Contracts\ManagesUserSessions;
use ReneRoscher\UserSessions\Contracts\SessionActivityResolver;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Contracts\UserAgentParser;
use ReneRoscher\UserSessions\Listeners\ForgetSessionOnLogout;
use ReneRoscher\UserSessions\Listeners\RevokeOnPasswordReset;
use ReneRoscher\UserSessions\Listeners\SyncOtherDeviceLogout;
use ReneRoscher\UserSessions\Recorder\DatabaseSessionRecorder;
use ReneRoscher\UserSessions\Support\CurrentUserSession;
use ReneRoscher\UserSessions\Support\NativeUserAgentParser;
use ReneRoscher\UserSessions\Support\StoreSessionActivityResolver;

class UserSessionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/user-sessions.php', 'user-sessions');

        $this->app->singleton(SessionRecorder::class, DatabaseSessionRecorder::class);

        $this->app->singleton(ManagesUserSessions::class, UserSessionRegistry::class);

        $this->app->singleton(UserAgentParser::class, function (Application $app): UserAgentParser {
            /** @var class-string<UserAgentParser> $parserClass */
            $parserClass = $app->make('config')->get('user-sessions.parser', NativeUserAgentParser::class);

            return new $parserClass;
        });

        $this->app->singleton(SessionActivityResolver::class, function (Application $app): SessionActivityResolver {
            /** @var class-string<SessionActivityResolver> $resolverClass */
            $resolverClass = $app->make('config')->get('user-sessions.activity_resolver', StoreSessionActivityResolver::class);

            return new $resolverClass;
        });

        $this->app->singleton(ShareSessionContext::class);
    }

    public function boot(): void
    {
        $this->loadFactoriesFrom(__DIR__.'/../database/factories');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'user-sessions-migrations');

        $this->publishes([
            __DIR__.'/../config/user-sessions.php' => config_path('user-sessions.php'),
        ], 'user-sessions-config');

        $this->registerMiddleware();

        $this->registerAliases();

        $this->registerEventListeners();

        $this->registerRequestMacro();

        $this->registerAboutCommand();
    }

    /**
     * Register the recording middleware into the configured group.
     *
     * Laravel 11+ manages middleware groups on the HTTP kernel; a router-only
     * pushMiddlewareToGroup() is undone by the kernel's syncMiddlewareToRouter(), so the
     * grouped route never runs it. appendMiddlewareToGroup() updates the authoritative
     * kernel groups and re-syncs. Deferred to booted() so the kernel already exists.
     */
    private function registerMiddleware(): void
    {
        $middlewareGroup = Config::get('user-sessions.middleware_group', 'web');

        if (! is_string($middlewareGroup)) {
            return;
        }

        $this->app->booted(function () use ($middlewareGroup): void {
            if ($this->app->bound(HttpKernel::class)) {
                $this->app->make(HttpKernel::class)->appendMiddlewareToGroup($middlewareGroup, Middleware\RecordSessions::class);
            }
        });
    }

    private function registerAliases(): void
    {
        $this->app->make('router')->aliasMiddleware('user-sessions.record', Middleware\RecordSessions::class);
    }

    private function registerEventListeners(): void
    {
        Event::listen(Logout::class, ForgetSessionOnLogout::class);
        Event::listen(CurrentDeviceLogout::class, ForgetSessionOnLogout::class);
        Event::listen(OtherDeviceLogout::class, SyncOtherDeviceLogout::class);

        // MassPrunable performs a bulk delete and only fires the framework's
        // ModelsPruned event — it never calls the model's pruning() hook. Translate
        // it into our own UserSessionsPruned event for the configured model.
        Event::listen(ModelsPruned::class, function (ModelsPruned $event): void {
            $model = Config::string('user-sessions.model', Models\UserSession::class);

            if ($event->model === $model && Config::boolean('user-sessions.events', true)) {
                Event::dispatch(new Events\UserSessionsPruned($event->count));
            }
        });

        if (config('user-sessions.revoke_on_password_reset', true)) {
            Event::listen(PasswordReset::class, RevokeOnPasswordReset::class);
        }
    }

    private function registerRequestMacro(): void
    {
        if (! config('user-sessions.request_macro', true)) {
            return;
        }

        Request::macro('userSession', function (bool $memoized = false): ?Models\UserSession {
            /** @var \Illuminate\Http\Request $this */
            return CurrentUserSession::resolve($this, $memoized);
        });
    }

    /**
     * Surface whether the debounce can actually work.
     *
     * On an array/null store Cache::add() never sees the previous marker, so the
     * debounce degrades to a database write on every request. `php artisan about`
     * flags that.
     */
    private function debounceCacheHealth(): string
    {
        $configured = Config::get('user-sessions.cache_store');
        $store = is_string($configured) ? $configured : Config::string('cache.default', 'file');

        if (Config::integer('user-sessions.sync_interval', 180) === 0) {
            return $store.' (debounce disabled: sync_interval=0)';
        }

        return in_array($store, ['array', 'null'], true)
            ? $store.' (INEFFECTIVE — writes every request, set user-sessions.cache_store)'
            : $store;
    }

    /**
     * Whether last_activity can be read back from the session store, or whether the
     * device list falls back to the debounced registry column.
     */
    private function activitySource(): string
    {
        if (! Config::boolean('user-sessions.resolve_activity_from_store', true)) {
            return 'registry table (store resolution disabled)';
        }

        return $this->app->make(SessionActivityResolver::class)->supported()
            ? 'session store (exact)'
            : 'registry table (store not introspectable, ±sync_interval)';
    }

    private function registerAboutCommand(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('User Sessions', function (): array {
                return [
                    'Session Driver' => config('session.driver', 'file'),
                    'Sync Interval' => Config::integer('user-sessions.sync_interval', 180).'s',
                    'Table' => config('user-sessions.table', 'user_sessions'),
                    'Events Enabled' => config('user-sessions.events', true) ? 'Yes' : 'No',
                    'Broadcast' => config('user-sessions.broadcast', false) ? 'Yes' : 'No',
                    'Debounce Cache' => $this->debounceCacheHealth(),
                    'Activity Source' => $this->activitySource(),
                ];
            });
        }
    }
}
