<?php

namespace App\Modules\Lead\DTOs;

// ─── Create Lead ──────────────────────────────────────────────────────────────
readonly class CreateLeadDTO
{
    public function __construct(
        public int     $contactId,
        public string  $source       = 'manual',
        public ?string $category     = null,
        public string  $priority     = 'medium',
        public ?string $notes        = null,
        public ?int    $assignedTo   = null,
        public ?int    $flowNodeId   = null,
        public ?int    $campaignId   = null,
        // "Lead Origin" — which specific number/session/account/campaign within `source` this
        // came from (a company can run several WhatsApp Cloud numbers, WA Chat sessions,
        // Instagram accounts and ad campaigns at once). originType is a short slug (e.g.
        // 'phone_number', 'wa_session', 'instagram_account', 'ad_campaign', 'widget', 'flow_node');
        // originId points at that row (no FK — the table depends on originType); originLabel is a
        // denormalized display snapshot so the lead still reads sensibly if that row is renamed later.
        public ?string $originType   = null,
        public ?int    $originId     = null,
        public ?string $originLabel  = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            contactId:   (int) $data['contact_id'],
            source:      $data['source']      ?? 'manual',
            category:    $data['category']    ?? null,
            priority:    $data['priority']    ?? 'medium',
            notes:       $data['notes']       ?? null,
            assignedTo:  isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            originLabel: $data['origin_label'] ?? null,
        );
    }

    // For auto-creation from webhook/flow
    public static function fromFlow(int $contactId, int $flowNodeId, ?string $category, ?int $campaignId = null, ?string $originLabel = null): self
    {
        return new self(
            contactId:  $contactId,
            source:     'flow',
            category:   $category,
            priority:   'medium',
            flowNodeId: $flowNodeId,
            campaignId: $campaignId,
            originType:  $originLabel ? 'flow_node' : null,
            originId:    $originLabel ? $flowNodeId : null,
            originLabel: $originLabel,
        );
    }
}
