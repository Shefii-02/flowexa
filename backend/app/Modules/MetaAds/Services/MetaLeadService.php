<?php

namespace App\Modules\MetaAds\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaLead;
use App\Models\MetaLeadForm;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Meta Instant Forms (lead ads) → CRM.
 *
 * The webhook path and the manual "pull recent leads" path both funnel through {@see persistLead()},
 * which records the raw lead in `meta_leads` and then materialises a Contact + Lead in the CRM,
 * de-duplicating on the contact's phone. Attribution (form / ad / campaign) is kept on the
 * `meta_leads` row and mirrored into the Lead's notes.
 */
class MetaLeadService
{
    public function __construct(private readonly MetaGraphClient $graph) {}

    // ── Webhook entry ─────────────────────────────────────────────────────

    /**
     * Handle one `leadgen` change from a Meta webhook. Resolves which connected account it belongs
     * to (via the ad, then the page), fetches the full lead, and persists it. Never throws — a bad
     * lead is logged and recorded as `failed` so the webhook still 200s.
     */
    public function ingestWebhookLead(array $value): void
    {
        $leadgenId = $value['leadgen_id'] ?? null;
        if (!$leadgenId) return;

        if (MetaLead::where('meta_leadgen_id', $leadgenId)->exists()) return; // already have it

        try {
            $account = $this->resolveAccount($value);
            if (!$account) {
                Log::warning('MetaLeadService: no connected account for leadgen webhook', $value);
                return;
            }
            $lead = $this->fetchLead($account, (string) $leadgenId);
            $this->persistLead($account, $lead, $value['ad_id'] ?? ($lead['ad_id'] ?? null), $value['form_id'] ?? ($lead['form_id'] ?? null));
        } catch (\Throwable $e) {
            Log::error('MetaLeadService: failed to ingest webhook lead', ['leadgen_id' => $leadgenId, 'error' => $e->getMessage()]);
            MetaLead::updateOrCreate(
                ['meta_leadgen_id' => $leadgenId],
                ['company_id' => 0, 'process_status' => 'failed', 'process_error' => $e->getMessage()]
            );
        }
    }

    // ── Graph reads ──────────────────────────────────────────────────────

    public function fetchLead(MetaAdAccount $account, string $leadgenId): array
    {
        return $this->graph->get($leadgenId, $account->access_token, [
            'fields' => 'id,created_time,ad_id,form_id,platform,field_data,campaign_id',
        ]);
    }

    /** Discover the Instant Forms on the account's page and upsert them. */
    public function syncLeadForms(MetaAdAccount $account): int
    {
        if (!$account->page_id) return 0;

        $forms = $this->graph->getAllPages("/{$account->page_id}/leadgen_forms", $account->access_token, [
            'fields' => 'id,name,status,questions,privacy_policy,leads_count',
        ]);

        foreach ($forms as $f) {
            MetaLeadForm::updateOrCreate(
                ['meta_form_id' => $f['id']],
                [
                    'company_id'         => $account->company_id,
                    'meta_ad_account_id' => $account->id,
                    'page_id'            => $account->page_id,
                    'name'               => $f['name'] ?? null,
                    'status'             => $f['status'] ?? null,
                    'questions'          => collect($f['questions'] ?? [])->map(fn ($q) => [
                        'key'   => $q['key'] ?? ($q['field_key'] ?? null),
                        'label' => $q['label'] ?? null,
                        'type'  => $q['type'] ?? null,
                    ])->all(),
                    'privacy_policy'     => $f['privacy_policy'] ?? null,
                    'leads_count'        => $f['leads_count'] ?? 0,
                    'last_synced_at'     => now(),
                ]
            );
        }
        return count($forms);
    }

    /**
     * Create an Instant Form on the account's page. `$spec` is our simplified shape:
     * `{ name, privacy_url, questions: [{type, label?, key?}], thank_you: {title, body, cta_text?, url?} }`.
     */
    public function createLeadForm(MetaAdAccount $account, array $spec): MetaLeadForm
    {
        if (!$account->page_id) {
            throw new \RuntimeException('Connect a Facebook Page to this ad account before creating a lead form.');
        }

        $questions = collect($spec['questions'] ?? [])
            ->map(fn ($q) => array_filter([
                'type' => strtoupper($q['type'] ?? 'CUSTOM'),
                'key'  => $q['key'] ?? null,
                'label' => $q['label'] ?? null,
            ]))
            ->values()->all();
        // A form must have at least one prefill field.
        if (empty($questions)) {
            $questions = [['type' => 'FULL_NAME'], ['type' => 'PHONE'], ['type' => 'EMAIL']];
        }

        $ty = $spec['thank_you'] ?? [];
        $payload = [
            'name'      => $spec['name'] ?? ($account->ad_account_name . ' lead form'),
            'questions' => json_encode($questions),
            'privacy_policy' => json_encode([
                'url'       => $spec['privacy_url'] ?? 'https://www.facebook.com',
                'link_text' => 'Privacy Policy',
            ]),
            'follow_up_action_url' => $ty['url'] ?? ($spec['privacy_url'] ?? 'https://www.facebook.com'),
            'thank_you_page' => json_encode(array_filter([
                'title'                 => $ty['title'] ?? 'Thanks!',
                'body'                  => $ty['body'] ?? "We'll be in touch shortly.",
                'button_type'           => 'VIEW_WEBSITE',
                'website_url'           => $ty['url'] ?? null,
                'button_text'           => $ty['cta_text'] ?? 'Done',
            ])),
        ];

        $res = $this->graph->post("/{$account->page_id}/leadgen_forms", $account->access_token, $payload);

        $form = MetaLeadForm::updateOrCreate(
            ['meta_form_id' => $res['id']],
            [
                'company_id'         => $account->company_id,
                'meta_ad_account_id' => $account->id,
                'page_id'            => $account->page_id,
                'name'               => $payload['name'],
                'status'             => 'ACTIVE',
                'questions'          => $questions,
                'last_synced_at'     => now(),
            ]
        );

        return $form;
    }

    /** Pull leads for one form that we don't have yet (backfill / catch-up on a missed webhook). */
    public function syncFormLeads(MetaLeadForm $form, int $max = 500): int
    {
        $account = $form->adAccount;
        if (!$account) return 0;

        $rows = $this->graph->getAllPages("/{$form->meta_form_id}/leads", $account->access_token, [
            'fields' => 'id,created_time,ad_id,form_id,platform,field_data,campaign_id',
        ], $max);

        $new = 0;
        foreach ($rows as $row) {
            if (MetaLead::where('meta_leadgen_id', $row['id'])->exists()) continue;
            $this->persistLead($account, $row, $row['ad_id'] ?? null, $form->meta_form_id);
            $new++;
        }
        $form->update(['last_synced_at' => now(), 'leads_count' => $form->leads()->count()]);
        return $new;
    }

    // ── Persist + CRM ────────────────────────────────────────────────────

    public function persistLead(MetaAdAccount $account, array $lead, ?string $adId, ?string $formId): MetaLead
    {
        $fields = $this->flattenFieldData($lead['field_data'] ?? []);

        $metaAd   = $adId ? MetaAd::where('meta_ad_id', $adId)->first() : null;
        $formRow  = $formId ? MetaLeadForm::where('meta_form_id', $formId)->first() : null;
        $campaign = $metaAd?->adSet?->campaign;

        return DB::transaction(function () use ($account, $lead, $fields, $metaAd, $formRow, $campaign) {
            $metaLead = MetaLead::updateOrCreate(
                ['meta_leadgen_id' => $lead['id']],
                [
                    'company_id'        => $account->company_id,
                    'meta_lead_form_id' => $formRow?->id,
                    'meta_ad_id'        => $metaAd?->id,
                    'meta_campaign_id'  => $campaign?->id,
                    'full_name'         => $fields['name'] ?? null,
                    'phone'             => $fields['phone'] ?? null,
                    'email'             => $fields['email'] ?? null,
                    'field_data'        => $lead['field_data'] ?? [],
                    'platform'          => $lead['platform'] ?? null,
                    'meta_created_time' => $lead['created_time'] ?? null,
                    'process_status'    => 'pending',
                ]
            );

            $phone = $this->normalizePhone($fields['phone'] ?? '');
            if (!$phone) {
                $metaLead->update(['process_status' => 'skipped', 'process_error' => 'No phone number in the lead', 'processed_at' => now()]);
                return $metaLead;
            }

            $contact = Contact::firstOrCreate(
                ['company_id' => $account->company_id, 'phone' => $phone],
                ['name' => $fields['name'] ?? null, 'email' => $fields['email'] ?? null],
            );
            if ($contact->wasRecentlyCreated === false) {
                // Fill gaps on an existing contact without clobbering.
                $contact->fill(array_filter([
                    'name'  => $contact->name  ?: ($fields['name'] ?? null),
                    'email' => $contact->email ?: ($fields['email'] ?? null),
                ]))->save();
            }

            $crmLead = $this->attachCrmLead($contact->id, $account->company_id, $metaLead, $campaign?->id, $campaign?->name);

            $metaLead->update([
                'contact_id'     => $contact->id,
                'lead_id'        => $crmLead?->id,
                'process_status' => 'processed',
                'processed_at'   => now(),
            ]);

            return $metaLead;
        });
    }

    /** Reuse an active CRM lead for this contact, else create one attributed to Meta Ads. */
    private function attachCrmLead(int $contactId, int $companyId, MetaLead $metaLead, ?int $campaignId, ?string $campaignName): ?Lead
    {
        $existing = Lead::where('company_id', $companyId)
            ->where('contact_id', $contactId)
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->latest()
            ->first();
        if ($existing) return $existing;

        $note = 'From Meta lead ad'
            . ($campaignName ? " · campaign: {$campaignName}" : '')
            . ($metaLead->form?->name ? " · form: {$metaLead->form->name}" : '');

        // A company can run several ad campaigns at once — record which one this lead came from.
        $originLabel = $campaignName ?: $metaLead->form?->name;

        try {
            /** @var \App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface $repo */
            $repo = app(\App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface::class);
            return $repo->create($companyId, new CreateLeadDTO(
                contactId: $contactId,
                source: 'meta_ads',
                notes: $note,
                originType: $originLabel ? 'ad_campaign' : null,
                originId: $campaignId,
                originLabel: $originLabel,
            ));
        } catch (\Throwable $e) {
            Log::warning('MetaLeadService: LeadRepository::create failed, writing a bare Lead', ['error' => $e->getMessage()]);
            return Lead::create([
                'company_id'   => $companyId,
                'contact_id'   => $contactId,
                'stage'        => 'new',
                'priority'     => 'medium',
                'source'       => 'meta_ads',
                'notes'        => $note,
                'origin_type'  => $originLabel ? 'ad_campaign' : null,
                'origin_id'    => $campaignId,
                'origin_label' => $originLabel,
            ]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Map Meta's `field_data` ([{name, values:[]}]) to name/phone/email + keep the rest by key. */
    private function flattenFieldData(array $fieldData): array
    {
        $out = [];
        foreach ($fieldData as $f) {
            $key = strtolower($f['name'] ?? '');
            $val = $f['values'][0] ?? null;
            if ($val === null) continue;
            $out[$key] = $val;
            if (in_array($key, ['full_name', 'name', 'first_name'], true)) $out['name'] ??= $val;
            if (str_contains($key, 'phone')) $out['phone'] ??= $val;
            if (str_contains($key, 'email')) $out['email'] ??= $val;
        }
        return $out;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return strlen($digits) >= 8 ? $digits : '';
    }

    private function resolveAccount(array $value): ?MetaAdAccount
    {
        if (!empty($value['ad_id'])) {
            $ad = MetaAd::where('meta_ad_id', $value['ad_id'])->first();
            $acc = $ad?->adSet?->adAccount;
            if ($acc) return $acc;
        }
        if (!empty($value['page_id'])) {
            return MetaAdAccount::where('page_id', $value['page_id'])->where('is_active', true)->first();
        }
        if (!empty($value['form_id'])) {
            return MetaLeadForm::where('meta_form_id', $value['form_id'])->first()?->adAccount;
        }
        return null;
    }
}
