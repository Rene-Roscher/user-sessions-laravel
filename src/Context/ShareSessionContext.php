<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Context;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use ReneRoscher\UserSessions\Contracts\UserAgentParser;
use ReneRoscher\UserSessions\Support\CurrentUserSession;

final class ShareSessionContext
{
    public function __construct(
        private UserAgentParser $parser,
    ) {}

    /**
     * Share everything that is knowable before the controller runs.
     *
     * This has to happen on the way IN. Jobs dispatched by a controller are serialized
     * at dispatch time — Laravel dehydrates the context into the payload right then — so
     * anything added on the way out reaches neither those jobs nor any log line the
     * controller writes. The device label only needs the live User-Agent, so it can be
     * shared this early; the registry row id cannot (see shareResolved()).
     */
    public function shareDevice(Request $request): void
    {
        if (! $this->enabled('device')) {
            return;
        }

        Context::add('device', $this->parser->parse($request->userAgent())->label());
    }

    /**
     * Share what is only knowable once the request has been handled.
     *
     * The registry row is loaded lazily by the userSession() macro, so its id exists only
     * after something asked for it during the request.
     */
    public function shareResolved(Request $request): void
    {
        if (! $this->enabled('user_session_id')) {
            return;
        }

        $this->shareSessionId($request);
    }

    public function share(Request $request): void
    {
        $this->shareDevice($request);
        $this->shareResolved($request);
    }

    private function enabled(string $key): bool
    {
        if (! Config::boolean('user-sessions.context.enabled', true)) {
            return false;
        }

        return in_array($key, Config::array('user-sessions.context.keys', ['device', 'user_session_id']), true);
    }

    /**
     * Share the registry row's ULID — never the session id itself.
     *
     * The session id is a bearer credential; putting it in Context would copy it into
     * every log line and every queued job payload dispatched from this request. The
     * ULID identifies the same session for correlation without being usable to
     * impersonate it.
     *
     * Only shared when the row was already loaded this request (PLAN §9.5): resolving
     * it here would add a query to the hot path, and the row usually does not exist yet
     * at this point anyway — the recorder runs after the response, in defer().
     */
    private function shareSessionId(Request $request): void
    {
        if (! Config::boolean('user-sessions.request_macro', true)) {
            return;
        }

        $session = CurrentUserSession::resolve($request, memoized: true);

        if ($session !== null) {
            Context::add('user_session_id', $session->getKey());
        }
    }
}
