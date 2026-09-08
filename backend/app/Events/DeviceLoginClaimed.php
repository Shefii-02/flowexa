<?php

namespace App\Events;

use App\Models\UserDevice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/** A pending QR/PIN challenge was just claimed by a phone — tell the web tab. */
class DeviceLoginClaimed implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $challengeId,
        public UserDevice $device,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}.devices")];
    }

    public function broadcastAs(): string
    {
        return 'device.claimed';
    }

    public function broadcastWith(): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'device' => [
                'id'          => $this->device->id,
                'device_name' => $this->device->device_name,
                'platform'    => $this->device->platform,
            ],
        ];
    }
}
