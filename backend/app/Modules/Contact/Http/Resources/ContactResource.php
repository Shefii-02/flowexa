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
            // The originating WhatsApp id (@c.us or @lid) — lets any consumer address this contact
            // directly instead of re-deriving a chat id from `phone` (which an @lid chat never had).
            'wa_id'           => $this->wa_id,
            'custom_fields'   => $this->custom_fields,
            'opted_in'        => $this->opted_in,
            'opted_out_at'    => $this->opted_out_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'crm_id'          => $this->crm_id,
            'created_at'      => $this->created_at->toIso8601String(),

            // AI / lead intelligence fields — real columns on the model that were previously
            // dropped on the floor here, leaving the CRM-details panel unable to show them.
            'lead_score'             => $this->lead_score,
            'lead_stage'             => $this->lead_stage,
            'conversation_summary'   => $this->conversation_summary,

            'labels' => $this->whenLoaded('labels',
                fn() => LabelResource::collection($this->labels)
            ),

            // The contact's current staff assignment, via current_assignment_id -> LeadAssignment.
            // Null (not omitted) once the relation is loaded but no assignment exists, so the panel
            // can tell "not loaded yet" apart from "loaded, nobody assigned".
            'assigned_to' => $this->whenLoaded('currentAssignment',
                fn() => $this->currentAssignment?->staff ? [
                    'id'    => $this->currentAssignment->staff->id,
                    'name'  => $this->currentAssignment->staff->name,
                    'email' => $this->currentAssignment->staff->email,
                ] : null
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
