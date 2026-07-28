<?php

// ─── RESOURCES ────────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'stage'          => $this->stage,
            'priority'       => $this->priority,
            'category'       => $this->category,
            'source'         => $this->source,
            'notes'          => $this->notes,
            'crm_id'         => $this->crm_id,
            'followed_up_at' => $this->followed_up_at?->toIso8601String(),
            'enrolled_at'    => $this->enrolled_at?->toIso8601String(),
            'assigned_at'    => $this->assigned_at?->toIso8601String(),
            'created_at'     => $this->created_at->toIso8601String(),
            'contact'    => $this->whenLoaded('contact', fn() => [
                'id'    => $this->contact->id,
                'name'  => $this->contact->name,
                'phone' => $this->contact->phone,
                'email' => $this->contact->email,
                'labels'=> $this->contact->relationLoaded('labels')
                    ? $this->contact->labels->map(fn($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])
                    : [],
            ]),
            'assigned_to' => $this->whenLoaded('assignedTo', fn() => $this->assignedTo ? [
                'id'         => $this->assignedTo->id,
                'name'       => $this->assignedTo->name,
                'email'      => $this->assignedTo->email,
                'department' => $this->assignedTo->department,
            ] : null),
            'assigned_by' => $this->whenLoaded('assignedBy', fn() => $this->assignedBy?->name),
            'flow_node'   => $this->whenLoaded('flowNode', fn() => $this->flowNode ? ['id' => $this->flowNode->id, 'title' => $this->flowNode->title] : null),
            'campaign'    => $this->whenLoaded('campaign', fn() => $this->campaign ? ['id' => $this->campaign->id, 'name' => $this->campaign->name] : null),
            'events'      => $this->whenLoaded('events', fn() => $this->events->map(fn($e) => [
                'id'         => $e->id,
                'event'      => $e->event,
                'payload'    => $e->payload,
                'user'       => $e->user?->name,
                'created_at' => $e->created_at->toIso8601String(),
            ])),
        ];
    }
}

