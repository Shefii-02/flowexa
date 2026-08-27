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
    public function paginate(int $companyId, CampaignFilterDTO $filter): LengthAwarePaginator
    {
        return Campaign::with(['creator:id,name,email', 'template:id,name,category'])
            ->where('company_id', $companyId)
            ->when($filter->status, fn($q) => $q->where('status', $filter->status))
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    // ─── Find by ID ───────────────────────────────────────────────────────────
    public function findById(int $id, int $companyId): ?Campaign
    {
        return Campaign::with(['creator:id,name,email', 'template'])
            ->where('id', $id)
            ->where('company_id', $companyId)
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
            'status'              => 'draft',
            'scheduled_at'        => $dto->scheduledAt,
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
            'scheduled_at'       => $dto->scheduledAt,
        ], fn($v) => !is_null($v));

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
    public function paginateContacts(int $campaignId, CampaignContactFilterDTO $filter): LengthAwarePaginator
    {
        return CampaignContact::with('contact:id,name,phone')
            ->where('campaign_id', $campaignId)
            ->when($filter->status, fn($q) => $q->where('status', $filter->status))
            ->when($filter->search, fn($q) =>
                $q->where('phone', 'like', "%{$filter->search}%")
                  ->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$filter->search}%"))
            )
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    // ─── Get pending batch for job ────────────────────────────────────────────
    public function getPendingContacts(int $campaignId, int $limit): Collection
    {
        return CampaignContact::where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->limit($limit)
            ->get();
    }

    // ─── Mark sent ────────────────────────────────────────────────────────────
    public function markContactSent(int $id, string $waMessageId): void
    {
        CampaignContact::where('id', $id)->update([
            'status'        => 'sent',
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
    public function markContactFailed(int $id, string $reason): void
    {
        CampaignContact::where('id', $id)->update([
            'status'        => 'failed',
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

    private function resolveCsvPhones(Campaign $campaign, int $companyId, $base): Collection
    {
        if (!$campaign->csv_file || !Storage::exists($campaign->csv_file)) {
            return collect();
        }

        $handle  = fopen(Storage::path($campaign->csv_file), 'r');
        $headers = array_map('trim', fgetcsv($handle));
        $phones  = [];

        while (($line = fgetcsv($handle)) !== false) {
            $data  = array_combine($headers, array_pad($line, count($headers), null));
            $phone = preg_replace('/\D/', '', trim($data['phone'] ?? ''));
            if ($phone) $phones[] = $phone;
        }

        fclose($handle);
        return $base->whereIn('phone', array_unique($phones))->get(['id', 'phone']);
    }
}
