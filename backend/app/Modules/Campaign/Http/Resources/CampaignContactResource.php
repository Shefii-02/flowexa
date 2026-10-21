<?php

namespace App\Modules\Campaign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;


class CampaignContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'phone'        => $this->phone,
            'status'       => $this->status,
            'attempts'     => $this->attempts,
            'error_type'   => $this->error_type,
            'retry_after'  => $this->retry_after?->toIso8601String(),
            'resent'       => $this->resent_at !== null,
            'resent_at'    => $this->resent_at?->toIso8601String(),
            'wa_message_id'=> $this->wa_message_id,
            'failed_reason'=> $this->failed_reason,
            'sent_at'      => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at'      => $this->read_at?->toIso8601String(),
            'contact_id'   => $this->contact_id,
            // customer replies to this campaign message (inbound messages after it was sent)
            'replies'      => (int) ($this->getAttribute('replies') ?? 0),
            'last_reply_at'=> $this->getAttribute('last_reply_at') ? \Illuminate\Support\Carbon::parse($this->getAttribute('last_reply_at'))->toIso8601String() : null,
            'contact'      => $this->whenLoaded('contact', fn() => ['id' => $this->contact?->id, 'name' => $this->contact?->name]),
        ];
    }
}
