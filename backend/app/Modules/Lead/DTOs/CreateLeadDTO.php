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
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            contactId:  (int) $data['contact_id'],
            source:     $data['source']      ?? 'manual',
            category:   $data['category']    ?? null,
            priority:   $data['priority']    ?? 'medium',
            notes:      $data['notes']       ?? null,
            assignedTo: isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
        );
    }

    // For auto-creation from webhook/flow
    public static function fromFlow(int $contactId, int $flowNodeId, ?string $category, ?int $campaignId = null): self
    {
        return new self(
            contactId:  $contactId,
            source:     'flow',
            category:   $category,
            priority:   'medium',
            flowNodeId: $flowNodeId,
            campaignId: $campaignId,
        );
    }
}
