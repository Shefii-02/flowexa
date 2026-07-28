<?php

namespace App\Modules\Contact\Http\Resources;

use App\Modules\Contact\DTOs\ImportResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Contact Resource ─────────────────────────────────────────────────────────
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'phone'           => $this->phone,
            'name'            => $this->name,
            'email'           => $this->email,
            'custom_fields'   => $this->custom_fields,
            'opted_in'        => $this->opted_in,
            'opted_out_at'    => $this->opted_out_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'crm_id'          => $this->crm_id,
            'created_at'      => $this->created_at->toIso8601String(),

            'labels' => $this->whenLoaded('labels',
                fn() => LabelResource::collection($this->labels)
            ),

            'leads' => $this->whenLoaded('leads',
                fn() => $this->leads->map(fn($l) => [
                    'id'          => $l->id,
                    'stage'       => $l->stage,
                    'priority'    => $l->priority,
                    'category'    => $l->category,
                    'assigned_to' => $l->assignedTo?->name,
                    'created_at'  => $l->created_at->toIso8601String(),
                ])
            ),

            'recent_messages' => $this->whenLoaded('messages',
                fn() => $this->messages->map(fn($m) => [
                    'id'        => $m->id,
                    'direction' => $m->direction,
                    'type'      => $m->type,
                    'status'    => $m->status,
                    'created_at'=> $m->created_at->toIso8601String(),
                ])
            ),
        ];
    }
}
