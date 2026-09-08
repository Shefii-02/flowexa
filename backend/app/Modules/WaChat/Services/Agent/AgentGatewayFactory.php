<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Modules\WaChat\Services\Agent\Contracts\AgentChannelGateway;
use InvalidArgumentException;

class AgentGatewayFactory
{
    public function __construct(
        private readonly OpenWaGateway $openWa,
        private readonly MetaCloudGateway $metaCloud,
    ) {}

    public function for(string $channel): AgentChannelGateway
    {
        return match ($channel) {
            'open_wa'    => $this->openWa,
            'meta_cloud' => $this->metaCloud,
            default      => throw new InvalidArgumentException("Unknown agent channel: {$channel}"),
        };
    }
}
