<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use ReneRoscher\UserSessions\Facades\UserSessions as UserSessionsFacade;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\SessionPayload;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    Cache::flush();
    $this->withoutDefer();
});

it('reads an unencrypted session payload for a registered session', function (): void {
    // Current request has an active session; the target session's payload lives in the store.
    session()->start();
    session()->getHandler()->write('stored-session', serialize(['locale' => 'de', '_token' => 'abc']));

    $session = UserSession::factory()->create([
        'session_id' => 'stored-session',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    $payload = UserSessionsFacade::payload($session);

    expect($payload)->toBeArray()
        ->and($payload)->toHaveKey('locale', 'de');
});

it('decrypts an encrypted session payload', function (): void {
    $encrypter = app(Encrypter::class);
    $data = ['user_id' => 7, 'locale' => 'de'];

    session()->start();
    session()->getHandler()->write('enc-session', $encrypter->encrypt(serialize($data)));

    $payload = new SessionPayload(session()->getHandler(), $encrypter, encrypt: true);

    expect($payload->read('enc-session'))->toBe($data);
});

it('returns null for an unknown session id', function (): void {
    expect(UserSessionsFacade::payload('does-not-exist'))->toBeNull();
});

it('returns null when the session store holds nothing for the id', function (): void {
    session()->start();

    $session = UserSession::factory()->create([
        'session_id' => 'never-written',
        'user_type' => User::class,
        'user_id' => $this->user->id,
    ]);

    expect(UserSessionsFacade::payload($session))->toBeNull();
});
