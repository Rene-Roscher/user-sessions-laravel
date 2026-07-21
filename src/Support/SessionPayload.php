<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Session\Store;
use RuntimeException;
use SessionHandlerInterface;

final class SessionPayload
{
    /**
     * Holds the handler, not the Store: reading a payload only needs read($id), and a
     * held Store is request-scoped state the architecture rules forbid (this object is
     * built per call, but the narrower dependency keeps the rule exception-free).
     */
    public function __construct(
        private SessionHandlerInterface $handler,
        private Encrypter $encrypter,
        private bool $encrypt = false,
    ) {}

    public static function forSession(Store $session): self
    {
        $app = app();

        $encrypt = (bool) config('session.encrypt', false);

        return new self(
            $session->getHandler(),
            $app->make(Encrypter::class),
            $encrypt,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $sessionId): ?array
    {
        $raw = $this->handler->read($sessionId);

        if ($raw === false || $raw === '') {
            return null;
        }

        if ($this->encrypt) {
            try {
                $raw = $this->encrypter->decrypt($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        if (! is_string($raw)) {
            return null;
        }

        try {
            $data = @unserialize($raw);
        } catch (RuntimeException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $payload = [];

        foreach ($data as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
