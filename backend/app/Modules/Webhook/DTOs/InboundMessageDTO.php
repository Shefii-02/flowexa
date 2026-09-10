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
        public ?string $caption        = null,
        public ?string $profileName    = null,  // the WhatsApp display name the customer set
    ) {}

    public static function fromMeta(array $message, string $phone, string $waId, ?string $profileName = null): self
    {
        $type            = $message['type'] ?? 'text';
        $text            = null;
        $interactiveType = null;
        $replyId         = null;
        $replyTitle      = null;

        if ($type === 'text') {
            $text = $message['text']['body'] ?? null;
        }

        // Quick-reply button on a *template* message — arrives as its own type, not
        // "interactive". Meta gives us the caption + a developer payload.
        if ($type === 'button') {
            $replyTitle = $message['button']['text'] ?? null;
            $replyId    = $message['button']['payload'] ?? $replyTitle;
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
            caption:          $message['image']['caption'] ?? $message['document']['caption'] ?? null,
            profileName:      $profileName,
        );
    }
}

