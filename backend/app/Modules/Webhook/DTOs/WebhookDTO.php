<?php

namespace App\Modules\Webhook\DTOs;

// ─── Inbound WhatsApp message ─────────────────────────────────────────────────
readonly class InboundMessageDTO
{
    public function __construct(
        public string  $phone,
        public string  $waId,
        public string  $messageId,
        public string  $type,          // text | interactive | image | document | audio
        public ?string $text           = null,
        public ?string $interactiveType= null, // button_reply | list_reply
        public ?string $replyId        = null, // button/list reply_id
        public ?string $replyTitle     = null,
        public ?array  $rawPayload     = null,
    ) {}

    public static function fromMeta(array $message, string $phone, string $waId): self
    {
        $type            = $message['type'] ?? 'text';
        $text            = null;
        $interactiveType = null;
        $replyId         = null;
        $replyTitle      = null;

        if ($type === 'text') {
            $text = $message['text']['body'] ?? null;
        }

        if ($type === 'interactive') {
            $interactive     = $message['interactive'];
            $interactiveType = $interactive['type'] ?? null;

            if ($interactiveType === 'button_reply') {
                $replyId    = $interactive['button_reply']['id']    ?? null;
                $replyTitle = $interactive['button_reply']['title'] ?? null;
            }

            if ($interactiveType === 'list_reply') {
                $replyId    = $interactive['list_reply']['id']    ?? null;
                $replyTitle = $interactive['list_reply']['title'] ?? null;
            }
        }

        return new self(
            phone:            $phone,
            waId:             $waId,
            messageId:        $message['id'],
            type:             $type,
            text:             $text,
            interactiveType:  $interactiveType,
            replyId:          $replyId,
            replyTitle:       $replyTitle,
            rawPayload:       $message,
        );
    }
}

// ─── Status update from Meta ──────────────────────────────────────────────────
readonly class StatusUpdateDTO
{
    public function __construct(
        public string $waMessageId,
        public string $status,      // sent | delivered | read | failed
        public ?string $errorMessage = null,
    ) {}

    public static function fromMeta(array $status): self
    {
        return new self(
            waMessageId:  $status['id'],
            status:       $status['status'],
            errorMessage: $status['errors'][0]['message'] ?? null,
        );
    }
}
