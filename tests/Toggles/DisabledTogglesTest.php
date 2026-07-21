<?php

declare(strict_types=1);

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use ReneRoscher\UserSessions\Context\ShareSessionContext;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Events\UserSessionCreated;
use ReneRoscher\UserSessions\Middleware\RecordSessions;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\CurrentUserSession;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create(['email' => 'off@example.com', 'password' => 'password']);
    $this->withoutDefer();
});

it('does not register the request macro when request_macro is false', function (): void {
    expect(Request::hasMacro('userSession'))->toBeFalse();
});

it('does not share the session id in context when the macro is disabled', function (): void {
    $request = Request::create('/', 'GET');
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(fn (): User => $this->user);

    $row = UserSession::factory()->create([
        'session_id' => $request->session()->getId(),
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect(CurrentUserSession::resolve($request)?->getKey())->toBe($row->getKey());

    app(ShareSessionContext::class)->share($request);

    expect(Context::get('user_session_id'))->toBeNull()
        ->and(Context::get('device'))->not->toBeNull();
});

it('does not revoke sessions on password reset when the toggle is false', function (): void {
    UserSession::factory()->create([
        'session_id' => 'off-pw-reset',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    event(new PasswordReset($this->user));

    expect(UserSession::where('session_id', 'off-pw-reset')->firstOrFail()->revoked_at)->toBeNull();
});

it('does not push the middleware into a group when middleware_group is null', function (): void {
    $groups = app(HttpKernel::class)->getMiddlewareGroups();

    foreach ($groups as $middleware) {
        expect($middleware)->not->toContain(RecordSessions::class);
    }
});

it('still dispatches the created event so applications can listen themselves', function (): void {
    Event::fake([UserSessionCreated::class]);

    app(SessionRecorder::class)->record('off-event', $this->user, '127.0.0.1', 'Mozilla/5.0');

    Event::assertDispatched(UserSessionCreated::class);
});
