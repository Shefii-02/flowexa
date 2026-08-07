<?php

namespace App\Modules\Conversation\Events;

use App\Models\WaMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow (not ShouldBroadcast) — this must go out immediately, not sit
// in a queue, or "realtime" stops being real. It's a tiny payload either way.
class WaMessageReceived implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public WaMessage $message) {}

    // Company-scoped private channel — every agent's browser subscribes to their
    // own company's channel, so a company only ever sees its own conversations.
    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->message->company_id}.conversations")];
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id'              => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'direction'       => $this->message->direction,
                'sender_type'     => $this->message->sender_type,
                // Which staff member sent it — null for inbound (customer) or bot/system messages.
                // This is what lets both staff tell each other's replies apart in the same thread.
                'sent_by'         => $this->message->sent_by,
                'sent_by_name'    => $this->message->sentBy?->name,
                'type'            => $this->message->type,
                'content'         => $this->message->content,
                'status'          => $this->message->status,
                'created_at'      => $this->message->created_at->toIso8601String(),
            ],
            'conversation' => [
                'id'               => $this->message->conversation->id,
                'phone'            => $this->message->conversation->phone,
                'contact_name'     => $this->message->conversation->contact_name,
                'contact_id'       => $this->message->conversation->contact_id,
                'assigned_to'      => $this->message->conversation->assigned_to,
                'status'           => $this->message->conversation->status,
                'unread_count'     => $this->message->conversation->unread_count,
                'last_message_at'  => $this->message->conversation->last_message_at?->toIso8601String(),
            ],
        ];
    }
}
