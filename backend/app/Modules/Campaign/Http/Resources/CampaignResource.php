<?php

namespace App\Modules\Campaign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = $this->total_contacts ?: 1;
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'description'         => $this->description,
            'target_type'         => $this->target_type,
            'target_labels'       => $this->target_labels,
            'throttle_per_minute' => $this->throttle_per_minute,
            'daily_limit'         => $this->daily_limit,
            'status'              => $this->status,
            'scheduled_at'        => $this->scheduled_at?->toIso8601String(),
            'started_at'          => $this->started_at?->toIso8601String(),
            'launched_at'         => $this->launched_at?->toIso8601String(),
            'completed_at'        => $this->completed_at?->toIso8601String(),
            // Lead attribution window — see CreateCampaignRequest / WaChatLeadAttributionService.
            'starts_at'           => $this->starts_at?->toIso8601String(),
            'ends_at'             => $this->ends_at?->toIso8601String(),
            'created_at'          => $this->created_at->toIso8601String(),
            'stats' => [
                'total_contacts' => $this->total_contacts,
                'sent'           => $this->sent,
                'delivered'      => $this->delivered,
                'read'           => $this->read,
                'failed'         => $this->failed,
                // failed contacts not yet resent — "Resend failed" is one-time, so this hits 0 afterwards
                'resendable_failed' => $this->failed > 0
                    ? \App\Models\CampaignContact::where('campaign_id', $this->id)->where('status', 'failed')->whereNull('resent_at')->count()
                    : 0,
                'pending'        => $this->pending,
                'wallet_debited' => $this->wallet_debited,
                'delivery_rate'  => round(($this->delivered / $total) * 100, 1),
                'read_rate'      => round(($this->read / $total) * 100, 1),
            ],
            'creator'  => $this->whenLoaded('creator', fn() => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'template' => $this->whenLoaded('template', fn() => ['id' => $this->template->id, 'name' => $this->template->name, 'category' => $this->template->category]),
        ];
    }
}


