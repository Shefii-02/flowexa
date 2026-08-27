<?php

namespace App\Modules\Campaign\Services;

use App\Models\Campaign;
use App\Modules\Campaign\DTOs\CampaignContactFilterDTO;
use App\Modules\Campaign\DTOs\CampaignFilterDTO;
use App\Modules\Campaign\DTOs\CreateCampaignDTO;
use App\Modules\Campaign\DTOs\LaunchResultDTO;
use App\Modules\Campaign\DTOs\UpdateCampaignDTO;
use App\Modules\Campaign\Exceptions\CampaignException;
use App\Modules\Campaign\Jobs\ProcessCampaignBatch;
use App\Modules\Campaign\Repositories\Interfaces\CampaignRepositoryInterface;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CampaignService
{
    private const EDITABLE_STATUSES = ['draft', 'scheduled'];
    private const LAUNCHABLE_STATUSES = ['draft', 'scheduled', 'paused'];

    public function __construct(
        private readonly CampaignRepositoryInterface $campaignRepository,
        private readonly WalletService               $walletService,
    ) {}

    // ─── List ─────────────────────────────────────────────────────────────────
    public function list(int $companyId, CampaignFilterDTO $filter): LengthAwarePaginator
    {
        return $this->campaignRepository->paginate($companyId, $filter);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────
    public function show(int $id, int $companyId): Campaign
    {
        $campaign = $this->campaignRepository->findById($id, $companyId);
        if (!$campaign) throw CampaignException::notFound();
        return $campaign;
    }

    // ─── Stats ────────────────────────────────────────────────────────────────
    public function stats(int $id, int $companyId): array
    {
        $campaign = $this->show($id, $companyId);
        $byStatus = $this->campaignRepository->countByStatus($id);
        $total    = $campaign->total_contacts ?: 1;

        return [
            'total_contacts' => $campaign->total_contacts,
            'sent'           => $campaign->sent,
            'delivered'      => $campaign->delivered,
            'read'           => $campaign->read,
            'failed'         => $campaign->failed,
            'pending'        => $campaign->pending,
            'wallet_debited' => $campaign->wallet_debited,
            'delivery_rate'  => round(($campaign->delivered / $total) * 100, 1),
            'read_rate'      => round(($campaign->read / $total) * 100, 1),
            'fail_rate'      => round(($campaign->failed / $total) * 100, 1),
            'by_status'      => $byStatus,
        ];
    }

    // ─── Create ───────────────────────────────────────────────────────────────
    public function create(int $companyId, int $userId, CreateCampaignDTO $dto): Campaign
    {
        return $this->campaignRepository->create($companyId, $userId, $dto);
    }

    // ─── Update ───────────────────────────────────────────────────────────────
    public function update(int $id, int $companyId, UpdateCampaignDTO $dto): Campaign
    {
        $campaign = $this->show($id, $companyId);

        if (!in_array($campaign->status, self::EDITABLE_STATUSES)) {
            throw CampaignException::notEditable($campaign->status);
        }

        return $this->campaignRepository->update($campaign, $dto);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function delete(int $id, int $companyId): void
    {
        $campaign = $this->show($id, $companyId);

        if ($campaign->status === 'running') {
            throw CampaignException::cannotDeleteRunning();
        }

        $this->campaignRepository->delete($campaign);
    }

    // ─── Launch ───────────────────────────────────────────────────────────────
    public function launch(int $id, int $companyId): LaunchResultDTO
    {
        $campaign = $this->show($id, $companyId);

        $company = auth()->user()->company;

        if (!in_array($campaign->status, self::LAUNCHABLE_STATUSES)) {
            throw CampaignException::notLaunchable($campaign->status);
        }

        // Resolve contact list
        $contacts = $this->campaignRepository->resolveContactPhones($companyId, $campaign);

        if ($contacts->isEmpty()) {
            throw CampaignException::noContacts();
        }

        $total  = $contacts->count();

        if ($company->wa_config == 'wallet') {

            $wallet = $this->walletService->getWallet($companyId);

            if ($wallet->balance < $total) {
                throw CampaignException::insufficientBalance($wallet->balance, $total);
            }
        }

        DB::transaction(function () use ($campaign, $contacts, $total, $companyId, $company) {
            // Clear any leftover pending rows
            $this->campaignRepository->clearPendingContacts($campaign->id);

            // Insert new contact rows
            $rows = $contacts->map(fn($c) => [
                'campaign_id' => $campaign->id,
                'contact_id'  => $c->id,
                'phone'       => $c->phone,
                'status'      => 'pending',
                'created_at'  => now(),
                'updated_at'  => now(),
            ])->toArray();

            $this->campaignRepository->insertContacts($campaign->id, $rows);

            // Update campaign status and counters
            $this->campaignRepository->updateStats($campaign->id, [
                'status'         => 'running',
                'total_contacts' => $total,
                'pending'        => $total,
                'sent'           => 0,
                'delivered'      => 0,
                'read'           => 0,
                'failed'         => 0,
                'started_at'     => now(),
            ]);


            if ($company->wa_config == 'wallet') {
                // Debit wallet upfront
                $this->walletService->debit(
                    companyId: $companyId,
                    amount: $total,
                    description: "Campaign '{$campaign->name}' — {$total} messages",
                    refId: (string) $campaign->id,
                    refType: 'campaign',
                );
            }

            // Update wallet_debited field
            $this->campaignRepository->updateStats($campaign->id, [
                'wallet_debited' => $total,
            ]);
        });

        // Dispatch background job
        ProcessCampaignBatch::dispatch($campaign->id, $companyId)
            ->onQueue('campaigns');

        $wallet->refresh();

        return new LaunchResultDTO(
            totalContacts: $total,
            walletDebited: $total,
            remainingBalance: $wallet->balance,
        );
    }

    // ─── Pause ────────────────────────────────────────────────────────────────
    public function pause(int $id, int $companyId): Campaign
    {
        $campaign = $this->show($id, $companyId);

        if ($campaign->status !== 'running') {
            throw CampaignException::notRunning();
        }

        return $this->campaignRepository->updateStatus($campaign, 'paused');
    }

    // ─── Resume ───────────────────────────────────────────────────────────────
    public function resume(int $id, int $companyId): Campaign
    {
        $campaign = $this->show($id, $companyId);

        if ($campaign->status !== 'paused') {
            throw CampaignException::notPaused();
        }

        $this->campaignRepository->updateStatus($campaign, 'running');

        ProcessCampaignBatch::dispatch($campaign->id, $companyId)
            ->onQueue('campaigns');

        return $campaign->fresh();
    }

    // ─── Resend failed ────────────────────────────────────────────────────────
    public function resendFailed(int $id, int $companyId): int
    {
        $campaign = $this->show($id, $companyId);
        $count    = $this->campaignRepository->resetFailedToPending($campaign->id);

        if (!$count) throw CampaignException::noFailedMessages();

        $wallet = $this->walletService->getWallet($companyId);
        if ($wallet->balance < $count) {
            throw CampaignException::insufficientBalance($wallet->balance, $count);
        }

        $this->walletService->debit($companyId, $count, "Resend failed — campaign #{$campaign->id}", (string) $campaign->id, 'campaign_resend');

        $this->campaignRepository->updateStats($campaign->id, [
            'status'  => 'running',
            'pending' => DB::raw("pending + {$count}"),
            'failed'  => DB::raw("failed - {$count}"),
        ]);

        ProcessCampaignBatch::dispatch($campaign->id, $companyId)->onQueue('campaigns');

        return $count;
    }

    // ─── Campaign contacts ────────────────────────────────────────────────────
    public function contacts(int $id, int $companyId, CampaignContactFilterDTO $filter): LengthAwarePaginator
    {
        $campaign = $this->show($id, $companyId);
        return $this->campaignRepository->paginateContacts($campaign->id, $filter);
    }
}
