<?php

namespace App\Modules\WaChat\Services\Agent\Contracts;

interface AgentChannelGateway
{
    /** Channel key this gateway serves: 'open_wa' | 'meta_cloud'. */
    public function channel(): string;

    /** Send a plain text WhatsApp message to a customer. Returns true on apparent success. */
    public function sendText(int $companyId, string $sessionRef, string $phone, string $text): bool;
}
