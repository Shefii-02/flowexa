<?php

namespace App\Modules\WaChat\Services\Agent;

/**
 * A channel-neutral inbound message handed to {@see ConversationalAgentService}.
 * Both the open-wa webhook and the Meta Cloud webhook normalise into this shape.
 */
readonly class AgentInbound
{
    public function __construct(
        public int     $companyId,
        public string  $channel,      // 'open_wa' | 'meta_cloud'
        public string  $sessionRef,   // open-wa: waha session_name · meta: phone_number_id
        public string  $phone,        // digits only, no @c.us / @s.whatsapp.net
        public string  $text,         // text body, media caption, or a "[voice message]" placeholder
        public string  $type = 'text',
        public bool    $fromGroup = false,
    ) {}

    public function hasText(): bool
    {
        return trim($this->text) !== '';
    }
}
