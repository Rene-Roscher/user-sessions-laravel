<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReneRoscher\UserSessions\Context\ShareSessionContext;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;

use function Illuminate\Support\defer;

class RecordSessions
{
    /**
     * Request-attribute flag set when the current session was revoked during this request,
     * so we do not immediately re-record the regenerated (now guest) session as active.
     */
    public const SKIP_RECORDING = 'user-sessions.skip-recording';

    public function __construct(
        private ShareSessionContext $shareContext,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // Share device context before the controller runs: jobs dispatched in a controller
        // dehydrate context at dispatch time, and log lines are written as the controller runs.
        $incomingUser = $request->user();
        $sharedDevice = false;

        if ($incomingUser !== null && $request->hasSession() && ! $this->isImpersonating($request, $incomingUser)) {
            $this->shareContext->shareDevice($request);
            $sharedDevice = true;
        }

        $response = $next($request);

        $user = $request->user();

        if ($user !== null
            && $request->hasSession()
            && ! $request->attributes->getBoolean(self::SKIP_RECORDING)
            && ! $this->isImpersonating($request, $user)) {
            $sessionId = $request->session()->getId();
            $ip = $request->ip() ?? '127.0.0.1';
            $ua = $request->userAgent();

            $deferred = defer(function () use ($sessionId, $user, $ip, $ua): void {
                app(SessionRecorder::class)->record($sessionId, $user, $ip, $ua);
            });

            if (Config::get('user-sessions.sync_on_failure', false)) {
                $deferred->always();
            }

            $this->shareContext->shareResolved($request);

            // Login requests were guests on the way in, so share the device label now.
            if (! $sharedDevice) {
                $this->shareContext->shareDevice($request);
            }
        }

        return $response;
    }

    /**
     * Whether the request is an impersonation session. When true, tracking is skipped
     * entirely so an admin logged in as a user does not trigger a "new device" alert or
     * a registry row for that user.
     */
    private function isImpersonating(Request $request, Authenticatable $user): bool
    {
        if (method_exists($user, 'isImpersonated') && $user->isImpersonated() === true) {
            return true;
        }

        $session = $request->session();

        /** @var list<mixed> $keys */
        $keys = (array) Config::get('user-sessions.impersonation.session_keys', []);

        foreach ($keys as $key) {
            if (is_string($key) && $session->has($key)) {
                return true;
            }
        }

        return false;
    }
}
