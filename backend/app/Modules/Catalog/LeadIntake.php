<?php

namespace App\Modules\Catalog;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Support\Facades\Log;

/**
 * The single "we now have a phone number — turn this into a real, routed lead" entry point, used by
 * every AI channel (website widget, Instagram DMs, WhatsApp). It:
 *   1. finds/creates the Contact by phone
 *   2. creates a CRM Lead (source-tagged, with the collected requirements as notes)
 *   3. runs the LeadAssignmentEngine → dedup check, staff routing, staff notification, SLA follow-up,
 *      and out-of-hours AI hand-off — i.e. the same pipeline a manual or campaign lead goes through
 */
class LeadIntake
{
    public function __construct(private readonly LeadAssignmentEngine $assignment) {}

    /**
     * @param array<string,mixed> $fields  collected fields — must contain a usable `phone`
     * @param string|null $originType  which specific number/session/account this came from —
     *   see the leads.origin_type doc-comment in the migration for the vocabulary
     * @return array{contact:Contact, lead:?Lead, assigned:bool}
     */
    public function capture(
        Company $company, array $fields, string $source, ?string $sourceRef = null,
        ?string $originType = null, ?int $originId = null, ?string $originLabel = null,
    ): array {
        $phone = $this->normalizePhone($fields['phone'] ?? '');
        if (!$phone) {
            return ['contact' => null, 'lead' => null, 'assigned' => false];
        }

        $contact = Contact::firstOrCreate(
            ['company_id' => $company->id, 'phone' => $phone],
            ['name' => $fields['name'] ?? null, 'email' => $fields['email'] ?? null],
        );
        if (!$contact->wasRecentlyCreated) {
            $contact->fill(array_filter([
                'name'  => $contact->name  ?: ($fields['name'] ?? null),
                'email' => $contact->email ?: ($fields['email'] ?? null),
            ]))->save();
        }

        $lead = $this->createLead($company->id, $contact->id, $fields, $source, $originType, $originId, $originLabel);

        $assigned = false;
        try {
            $this->assignment->assign($company, $contact->fresh(), $this->sourceType($source), null, $sourceRef, 'auto', $lead);
            $assigned = true;
        } catch (\Throwable $e) {
            Log::warning('LeadIntake: assignment engine failed', ['source' => $source, 'error' => $e->getMessage()]);
        }

        return ['contact' => $contact, 'lead' => $lead, 'assigned' => $assigned];
    }

    private function createLead(
        int $companyId, int $contactId, array $fields, string $source,
        ?string $originType, ?int $originId, ?string $originLabel,
    ): ?Lead {
        $existing = Lead::where('company_id', $companyId)->where('contact_id', $contactId)
            ->whereNotIn('stage', ['enrolled', 'lost'])->latest()->first();
        if ($existing) {
            return $existing;
        }

        $note = ucfirst(str_replace('_', ' ', $source)) . ' · ' . collect($fields)
            ->except(['name', 'phone', 'email'])
            ->map(fn ($v, $k) => "$k: " . (is_bool($v) ? ($v ? 'yes' : 'no') : $v))
            ->implode(', ');

        try {
            /** @var \App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface $repo */
            $repo = app(\App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface::class);
            return $repo->create($companyId, new CreateLeadDTO(
                contactId: $contactId,
                source: $this->sourceType($source),
                notes: $note,
                originType: $originType,
                originId: $originId,
                originLabel: $originLabel,
            ));
        } catch (\Throwable $e) {
            Log::warning('LeadIntake: repo create failed, bare Lead', ['error' => $e->getMessage()]);
            return Lead::create([
                'company_id' => $companyId, 'contact_id' => $contactId,
                'stage' => 'new', 'priority' => 'high', 'source' => $this->sourceType($source), 'notes' => $note,
                'origin_type' => $originType, 'origin_id' => $originId, 'origin_label' => $originLabel,
            ]);
        }
    }

    /** Map an internal channel key to the CRM/assignment source vocabulary. */
    private function sourceType(string $source): string
    {
        return match ($source) {
            'website_widget' => 'website_widget',
            'instagram_dm', 'instagram' => 'instagram',
            'whatsapp' => 'whatsapp',
            default => $source,
        };
    }

    private function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        return strlen($d) >= 8 ? $d : '';
    }
}
