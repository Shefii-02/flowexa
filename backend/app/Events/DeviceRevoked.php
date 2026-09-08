<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A linked device was removed. The web device-manager refreshes its list; the
 * phone app (subscribed to the same channel) logs itself out and goes home.
 */
class DeviceRevoked implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $deviceId,
        public string $deviceUid,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}.devices")];
    }

    public function broadcastAs(): string
    {
        return 'device.revoked';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'device_uid' => $this->deviceUid];
    }
}
