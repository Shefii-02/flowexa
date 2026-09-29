<?php

namespace App\Modules\WaChat\Services\Agent\Contracts;

interface AgentChannelGateway
{
    /** Channel key this gateway serves: 'open_wa' | 'meta_cloud'. */
    public function channel(): string;

    /** Send a plain text WhatsApp message to a customer. Returns true on apparent success. */
    public function sendText(int $companyId, string $sessionRef, string $phone, string $text): bool;

    /**
     * Send a voice-note reply — used when the inbound message that triggered this turn was
     * itself a voice note (see ConversationalAgentService::run()). $audioUrl is a publicly
     * fetchable URL to the synthesized clip (VoiceService::storePublicly). Returns true on
     * apparent success.
     */
    public function sendAudio(int $companyId, string $sessionRef, string $phone, string $audioUrl): bool;
}
