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
            'wa_message_id'=> $this->wa_message_id,
            'failed_reason'=> $this->failed_reason,
            'sent_at'      => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at'      => $this->read_at?->toIso8601String(),
            'contact'      => $this->whenLoaded('contact', fn() => ['id' => $this->contact?->id, 'name' => $this->contact?->name]),
        ];
    }
}
