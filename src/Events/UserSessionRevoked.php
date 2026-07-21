<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use ReneRoscher\UserSessions\Models\UserSession;

class UserSessionRevoked implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public UserSession $session,
        public ?string $revokedBy = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (! Config::boolean('user-sessions.broadcast', false)) {
            return [];
        }

        return [
            new PrivateChannel('user-sessions.'.str_replace('\\', '.', $this->session->user_type).'.'.$this->session->user_id),
        ];
    }

    public function broadcastWhen(): bool
    {
        return Config::boolean('user-sessions.broadcast', false);
    }

    public function broadcastAs(): string
    {
        return 'session.revoked';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // No session_id — see UserSessionCreated::broadcastWith().
        return [
            'id' => $this->session->getKey(),
            'revoked_by' => $this->revokedBy,
        ];
    }
}
