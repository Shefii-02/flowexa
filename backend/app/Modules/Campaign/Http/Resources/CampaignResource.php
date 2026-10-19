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
            'stats' => $this->liveStats($total),
            // Who this campaign sends from, and (before launch, when total_contacts is still 0) how many people it will reach.
            'wa_phone_number' => $this->whenLoaded('waPhoneNumber', fn () => $this->waPhoneNumber ? [
                'id' => $this->waPhoneNumber->id, 'label' => $this->waPhoneNumber->label,
                'display_phone_number' => $this->waPhoneNumber->display_number, 'status' => $this->waPhoneNumber->status,
            ] : null),
            'estimated_contacts' => in_array($this->status, ['draft', 'scheduled'], true) && !$this->total_contacts
                ? app(\App\Modules\Campaign\Repositories\Interfaces\CampaignRepositoryInterface::class)->estimateContacts($this->company_id, $this->resource)
                : null,
            'creator'  => $this->whenLoaded('creator', fn() => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'template' => $this->whenLoaded('template', fn() => ['id' => $this->template->id, 'name' => $this->template->name, 'category' => $this->template->category]),
        ];
    }

    /**
     * Counts come from the real campaign_contacts rows (not the stored counter columns), so editing
     * or resending rows is reflected immediately and "Failed" / "Resend" can never disagree.
     */
    private function liveStats(int $total): array
    {
        $by = \App\Models\CampaignContact::where('campaign_id', $this->id)
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $read      = (int) ($by['read'] ?? 0);
        $delivered = $read + (int) ($by['delivered'] ?? 0);
        $sent      = $delivered + (int) ($by['sent'] ?? 0);
        $failed    = (int) ($by['failed'] ?? 0);

        return [
            'total_contacts' => $this->total_contacts,
            'sent'           => $sent,
            'delivered'      => $delivered,
            'read'           => $read,
            'failed'         => $failed,
            // failed rows that a resend would actually pick up (not already waiting in the queue)
            'resendable_failed' => $failed > 0 ? \App\Models\CampaignContact::resendable($this->id)->count() : 0,
            'pending'        => (int) ($by['pending'] ?? 0),
            'wallet_debited' => $this->wallet_debited,
            'delivery_rate'  => round(($delivered / $total) * 100, 1),
            'read_rate'      => round(($read / $total) * 100, 1),
        ];
    }
}
