<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Contracts\SessionRecorder;
use ReneRoscher\UserSessions\Facades\UserSessions as UserSessionsFacade;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\UuidUser;

beforeEach(function (): void {
    Cache::flush();
    $this->withoutDefer();
});

// §6.1 / §12 Test 10 — explicit string morph columns must work with non-integer keys.
it('records and revokes sessions for a UUID-keyed user', function (): void {
    $user = UuidUser::create([
        'email' => 'uuid@example.com',
        'password' => 'password',
    ]);

    expect($user->getKey())->toBeString();

    app(SessionRecorder::class)->record('uuid-sess', $user, '1.1.1.1', 'Chrome');

    $this->assertDatabaseHas('user_sessions', [
        'session_id' => 'uuid-sess',
        'user_type' => UuidUser::class,
        'user_id' => $user->getKey(),
    ]);

    expect(UserSessionsFacade::revokeAll($user))->toBe(1);
});

it('keeps UUID-user sessions separate from integer-user sessions', function (): void {
    $intUser = User::create([
        'email' => 'int@example.com',
        'password' => 'password',
    ]);
    $uuidUser = UuidUser::create([
        'email' => 'uuid@example.com',
        'password' => 'password',
    ]);

    storeSession('int-sess');
    storeSession('uuid-sess');

    $recorder = app(SessionRecorder::class);
    $recorder->record('int-sess', $intUser, '1.1.1.1', 'Chrome');
    $recorder->record('uuid-sess', $uuidUser, '2.2.2.2', 'Chrome');

    $intSessions = UserSessionsFacade::for($intUser);
    $uuidSessions = UserSessionsFacade::for($uuidUser);

    expect($intSessions)->toHaveCount(1)
        ->and($uuidSessions)->toHaveCount(1)
        ->and($uuidSessions->first()->user_type)->toBe(UuidUser::class)
        ->and($uuidSessions->first()->user_id)->toEqual($uuidUser->getKey());
});
