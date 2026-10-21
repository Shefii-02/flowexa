<?php

namespace App\Modules\Campaign\Repositories;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Modules\Campaign\DTOs\CampaignContactFilterDTO;
use App\Modules\Campaign\DTOs\CampaignFilterDTO;
use App\Modules\Campaign\DTOs\CreateCampaignDTO;
use App\Modules\Campaign\DTOs\UpdateCampaignDTO;
use App\Modules\Campaign\Repositories\Interfaces\CampaignRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class CampaignRepository implements CampaignRepositoryInterface
{
    // ─── Paginate campaigns ───────────────────────────────────────────────────
    /**
     * @param array<int>|null $allowedNumberIds Restricts to campaigns on these WA Cloud
     *   numbers, plus any campaign with no number attached. Null = unrestricted — see
     *   User::allowedAccountIds('phone_number').
     */
    public function paginate(int $companyId, CampaignFilterDTO $filter, ?array $allowedNumberIds = null): LengthAwarePaginator
    {
        return Campaign::with(['creator:id,name,email', 'template:id,name,category', 'waPhoneNumber:id,label,display_number,status'])
            ->where('company_id', $companyId)
            ->when($allowedNumberIds !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereNull('wa_phone_number_id')->orWhereIn('wa_phone_number_id', $allowedNumberIds)
            ))
            ->when($filter->status, fn($q) => $q->where('status', $filter->status))
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    // ─── Find by ID ───────────────────────────────────────────────────────────
    /** @param array<int>|null $allowedNumberIds see paginate() */
    public function findById(int $id, int $companyId, ?array $allowedNumberIds = null): ?Campaign
    {
        return Campaign::with(['creator:id,name,email', 'template', 'waPhoneNumber:id,label,display_number,status'])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->when($allowedNumberIds !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereNull('wa_phone_number_id')->orWhereIn('wa_phone_number_id', $allowedNumberIds)
            ))
            ->first();
    }

    // ─── Create ───────────────────────────────────────────────────────────────
    public function create(int $companyId, int $userId, CreateCampaignDTO $dto): Campaign
    {
        return Campaign::create([
            'company_id'          => $companyId,
            'created_by'          => $userId,
            'template_id'         => $dto->templateId,
            'wa_phone_number_id'  => $dto->waPhoneNumberId,
            'name'                => $dto->name,
            'description'         => $dto->description,
            'template_variables'  => $dto->templateVariables,
            'target_type'         => $dto->targetType,
            'target_labels'       => $dto->targetLabels,
            'csv_file'            => $dto->csvFilePath,
            'throttle_per_minute' => $dto->throttlePerMinute,
            'daily_limit'         => $dto->dailyLimit,
            'auto_retry'          => $dto->autoRetry,
            'status'              => $dto->scheduledAt ? 'scheduled' : 'draft',
            'scheduled_at'        => $dto->scheduledAt,
            'starts_at'           => $dto->startsAt,
            'ends_at'             => $dto->endsAt,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────
    public function update(Campaign $campaign, UpdateCampaignDTO $dto): Campaign
    {
        $data = array_filter([
            'name'               => $dto->name,
            'description'        => $dto->description,
            'template_variables' => $dto->templateVariables,
            'throttle_per_minute'=> $dto->throttlePerMinute,
            'daily_limit'        => $dto->dailyLimit,
            'scheduled_at'       => $dto->scheduledAt,
            'starts_at'          => $dto->startsAt,
            'ends_at'            => $dto->endsAt,
        ], fn($v) => !is_null($v));

        // Keep status in sync with the schedule while the campaign is still
        // pre-launch, so wa-cloud:dispatch-scheduled-campaigns can pick it up.
        if (in_array($campaign->status, ['draft', 'scheduled'], true) && array_key_exists('scheduled_at', $data)) {
            $data['status'] = $data['scheduled_at'] ? 'scheduled' : 'draft';
        }

        $campaign->update($data);
        return $campaign->fresh(['creator', 'template']);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function delete(Campaign $campaign): void
    {
        if ($campaign->csv_file) {
            Storage::delete($campaign->csv_file);
        }
        $campaign->delete();
    }

    // ─── Status update ────────────────────────────────────────────────────────
    public function updateStatus(Campaign $campaign, string $status): Campaign
    {
        $extra = match ($status) {
            'running'   => ['started_at'   => now()],
            'completed' => ['completed_at' => now()],
            default     => [],
        };

        $campaign->update(array_merge(['status' => $status], $extra));
        return $campaign->fresh();
    }

    // ─── Bulk update stats ────────────────────────────────────────────────────
    public function updateStats(int $campaignId, array $stats): void
    {
        Campaign::where('id', $campaignId)->update($stats);
    }

    // ─── Insert campaign contacts in bulk ─────────────────────────────────────
    public function insertContacts(int $campaignId, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            CampaignContact::insert($chunk);
        }
    }

    // ─── Clear pending contacts (before re-launch) ────────────────────────────
    public function clearPendingContacts(int $campaignId): void
    {
        CampaignContact::where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->delete();
    }

    // ─── Paginate campaign contacts ───────────────────────────────────────────
    /**
     * Customer replies to a campaign: inbound messages that arrived in the contact's chat AFTER the campaign message
     * was sent to them. A contact is matched by contact_id or by phone (with or without "+").
     */
    private function replyQuery(int $campaignId, int $companyId)
    {
        return \Illuminate\Support\Facades\DB::table('campaign_contacts as cc')
            ->join('wa_conversations as c', fn ($j) => $j->where('c.company_id', $companyId)
                ->where(fn ($w) => $w->whereColumn('c.contact_id', 'cc.contact_id')->orWhereRaw("c.phone = REPLACE(cc.phone, '+', '')")))
            ->join('wa_messages as m', fn ($j) => $j->on('m.conversation_id', '=', 'c.id')->where('m.direction', 'inbound')->whereColumn('m.created_at', '>=', 'cc.sent_at'))
            ->where('cc.campaign_id', $campaignId)->whereNotNull('cc.sent_at');
    }

    /** @return array{responded:int, responses:int} contacts who replied, and how many reply messages in total */
    public function replyTotals(int $campaignId, int $companyId): array
    {
        $r = $this->replyQuery($campaignId, $companyId)->selectRaw('count(distinct cc.id) as responded, count(m.id) as responses')->first();
        return ['responded' => (int) ($r->responded ?? 0), 'responses' => (int) ($r->responses ?? 0)];
    }

    public function paginateContacts(int $campaignId, CampaignContactFilterDTO $filter): LengthAwarePaginator
    {
        $companyId = (int) \App\Models\Campaign::where('id', $campaignId)->value('company_id');
        $page = CampaignContact::with('contact:id,name,phone')
            ->where('campaign_id', $campaignId)
            ->when($filter->status === 'done', fn($q) => $q->whereIn('status', ['sent', 'delivered', 'read']))
            // contacts who answered the campaign message
            ->when($filter->status === 'replied', fn($q) => $q->whereIn('campaign_contacts.id', $this->replyQuery($campaignId, $companyId)->select('cc.id')))
            ->when($filter->status && !in_array($filter->status, ['done', 'replied'], true), fn($q) => $q->where('status', $filter->status))
            // grouped: a bare orWhere here escaped the campaign_id scope and matched other campaigns' contacts
            ->when($filter->search, fn($q) => $q->where(fn($w) =>
                $w->where('phone', 'like', "%{$filter->search}%")
                  ->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$filter->search}%"))
            ))
            ->orderBy('id')
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);

        // reply count + time of the last reply, for the rows on this page only
        $ids = $page->getCollection()->pluck('id');
        if ($ids->isNotEmpty()) {
            $rep = $this->replyQuery($campaignId, $companyId)->whereIn('cc.id', $ids)
                ->selectRaw('cc.id, count(m.id) as replies, max(m.created_at) as last_reply_at')->groupBy('cc.id')->get()->keyBy('id');
            $page->getCollection()->each(function ($row) use ($rep) {
                $row->setAttribute('replies', (int) ($rep[$row->id]->replies ?? 0));
                $row->setAttribute('last_reply_at', $rep[$row->id]->last_reply_at ?? null);
            });
        }
        return $page;
    }

    // ─── Get pending batch for job ────────────────────────────────────────────
    public function getPendingContacts(int $campaignId, int $limit): Collection
    {
        // Most engaged first: contacts who messaged us most recently are least likely to hit Meta's
        // marketing cap (131049), so they go out in the earliest batches; never-contacted ones last.
        return CampaignContact::select('campaign_contacts.*')
            ->leftJoin('contacts', 'contacts.id', '=', 'campaign_contacts.contact_id')
            ->where('campaign_contacts.campaign_id', $campaignId)
            ->where('campaign_contacts.status', 'pending')
            ->orderByRaw('contacts.last_message_at IS NULL')
            ->orderByDesc('contacts.last_message_at')
            ->orderBy('campaign_contacts.id')
            ->limit($limit)
            ->get();
    }

    /** Pending rows that have no queue job yet (scheduled_for is null), most-engaged first. */
    public function getUnqueuedPendingContacts(int $campaignId, int $limit): Collection
    {
        return CampaignContact::select('campaign_contacts.*')
            ->leftJoin('contacts', 'contacts.id', '=', 'campaign_contacts.contact_id')
            ->where('campaign_contacts.campaign_id', $campaignId)
            ->where('campaign_contacts.status', 'pending')
            ->whereNull('campaign_contacts.scheduled_for')
            ->orderByRaw('contacts.last_message_at IS NULL')
            ->orderByDesc('contacts.last_message_at')
            ->orderBy('campaign_contacts.id')
            ->limit($limit)
            ->get();
    }

    public function hasUnqueuedPendingContacts(int $campaignId): bool
    {
        return CampaignContact::where('campaign_id', $campaignId)->where('status', 'pending')->whereNull('scheduled_for')->exists();
    }

    // ─── Mark sent ────────────────────────────────────────────────────────────
    public function markContactSent(int $id, string $waMessageId, int $attempts = 1): void
    {
        CampaignContact::where('id', $id)->update([
            'status'        => 'sent',
            'attempts'      => $attempts,
            'failed_reason' => null,
            'error_type'    => null,
            'wa_message_id' => $waMessageId,
            'sent_at'       => now(),
        ]);
    }

    // ─── Mark delivered (webhook) ─────────────────────────────────────────────
    public function markContactDelivered(string $waMessageId): void
    {
        CampaignContact::where('wa_message_id', $waMessageId)->update([
            'status'       => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    // ─── Mark read (webhook) ──────────────────────────────────────────────────
    public function markContactRead(string $waMessageId): void
    {
        CampaignContact::where('wa_message_id', $waMessageId)->update([
            'status'  => 'read',
            'read_at' => now(),
        ]);
    }

    // ─── Mark failed ──────────────────────────────────────────────────────────
    public function markContactFailed(int $id, string $reason, int $attempts = 1, ?string $errorType = null, $retryAfter = null): void
    {
        CampaignContact::where('id', $id)->update([
            'status'        => 'failed',
            'retry_after'   => $retryAfter,
            'attempts'      => $attempts,
            'error_type'    => $errorType,
            'failed_reason' => $reason,
        ]);
    }

    // ─── Count by status ──────────────────────────────────────────────────────
    public function countByStatus(int $campaignId): array
    {
        return CampaignContact::where('campaign_id', $campaignId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    // ─── Reset failed to pending (resend) ────────────────────────────────────
    public function resetFailedToPending(int $campaignId): int
    {
        return CampaignContact::where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->update([
                'status'        => 'pending',
                'failed_reason' => null,
                'wa_message_id' => null,
            ]);
    }

    // ─── Resolve contact phones based on target_type ──────────────────────────
    public function resolveContactPhones(int $companyId, Campaign $campaign): Collection
    {
        $base = Contact::where('company_id', $companyId)->where('opted_in', true);

        return match ($campaign->target_type) {
            'all' => $base->get(['id', 'phone']),

            'labels' => $base->whereHas('labels', fn($q) =>
                $q->whereIn('contact_labels.id', $campaign->target_labels ?? [])
            )->get(['id', 'phone']),

            'csv' => $this->resolveCsvPhones($campaign, $companyId, $base),

            default => collect(),
        };
    }

    /**
     * Custom list: every valid number in the CSV is messaged, whether or not it is already a contact.
     * Unknown numbers are added as contacts (name taken from the CSV); numbers that opted out are skipped.
     * Header names are flexible (phone / number / mobile / whatsapp ...; name), or a header-less one-column list.
     */
    /** How many people a not-yet-launched campaign will go to (read-only: creates nothing). */
    public function estimateContacts(int $companyId, Campaign $campaign): int
    {
        $base = Contact::where('company_id', $companyId)->where('opted_in', true);
        return match ($campaign->target_type) {
            'all'    => $base->count(),
            'labels' => $base->whereHas('labels', fn ($q) => $q->whereIn('contact_labels.id', $campaign->target_labels ?? []))->count(),
            'csv'    => $this->countCsvNumbers($campaign),
            default  => 0,
        };
    }

    private function countCsvNumbers(Campaign $campaign): int
    {
        if (!$campaign->csv_file || !Storage::exists($campaign->csv_file)) return 0;
        $h = fopen(Storage::path($campaign->csv_file), 'r');
        $first = fgetcsv($h);
        if (!$first) { fclose($h); return 0; }
        $first[0] = ltrim((string) $first[0], "\xEF\xBB\xBF");
        $col = null;
        foreach ($first as $i => $x) {
            if (in_array(strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $x)), ['phone', 'number', 'mobile', 'mobilenumber', 'phonenumber', 'whatsapp', 'whatsappnumber', 'contact'], true)) { $col = $i; break; }
        }
        $rows = [];
        if ($col === null) { $col = 0; $rows[] = $first; }
        while (($l = fgetcsv($h)) !== false) $rows[] = $l;
        fclose($h);
        $set = [];
        foreach ($rows as $l) {
            $d = preg_replace('/\D+/', '', (string) ($l[$col] ?? ''));
            if (preg_match('/^\d{8,15}$/', $d)) $set[$d] = true;
        }
        return count($set);
    }

    private function resolveCsvPhones(Campaign $campaign, int $companyId, $base): Collection
    {
        if (!$campaign->csv_file || !Storage::exists($campaign->csv_file)) {
            return collect();
        }

        $handle = fopen(Storage::path($campaign->csv_file), 'r');
        $first  = fgetcsv($handle);
        if (!$first) { fclose($handle); return collect(); }
        $first[0] = ltrim((string) $first[0], "\xEF\xBB\xBF"); // UTF-8 BOM

        $norm    = fn ($h) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $h));
        $headers = array_map($norm, $first);
        $phoneCol = null; $nameCol = null;
        foreach ($headers as $i => $h) {
            if ($phoneCol === null && in_array($h, ['phone', 'number', 'mobile', 'mobilenumber', 'phonenumber', 'whatsapp', 'whatsappnumber', 'contact'], true)) $phoneCol = $i;
            if ($nameCol === null && in_array($h, ['name', 'fullname', 'customername'], true)) $nameCol = $i;
        }
        $rows = [];
        if ($phoneCol === null) {           // no recognisable header -> first column is the number, first row is data
            $phoneCol = 0;
            $rows[] = $first;
        }
        while (($line = fgetcsv($handle)) !== false) { $rows[] = $line; }
        fclose($handle);

        $list = []; // phone => name
        foreach ($rows as $line) {
            $phone = preg_replace('/\D+/', '', (string) ($line[$phoneCol] ?? ''));
            if (!preg_match('/^\d{8,15}$/', $phone) || isset($list[$phone])) continue;
            $list[$phone] = $nameCol !== null ? trim((string) ($line[$nameCol] ?? '')) : '';
        }
        if (!$list) return collect();

        $existing = Contact::where('company_id', $companyId)->whereIn('phone', array_keys($list))->get(['id', 'phone', 'opted_in'])->keyBy('phone');
        foreach ($list as $phone => $name) {
            if ($existing->has($phone)) continue;
            $c = Contact::create(['company_id' => $companyId, 'phone' => $phone, 'name' => $name ?: null, 'opted_in' => true]);
            $existing[$phone] = $c;
        }

        return $existing->filter(fn ($c) => $c->opted_in)->values();
    }
}
