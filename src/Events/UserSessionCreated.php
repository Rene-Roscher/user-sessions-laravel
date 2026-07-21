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

class UserSessionCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public UserSession $session,
        public bool $isNewDevice = false,
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
        return 'session.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Deliberately no session_id: the payload travels to the browser and through a
        // third-party broadcaster, and a session id is a bearer credential. The ULID
        // identifies the row for a live-updating device list without being usable.
        return [
            'id' => $this->session->getKey(),
            'is_new_device' => $this->isNewDevice,
            'device' => $this->session->device_label,
            'ip_address' => $this->session->ip_address,
            'platform' => $this->session->platform,
            'browser' => $this->session->browser,
        ];
    }
}
