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
    public function list(int $companyId, CampaignFilterDTO $filter, ?array $allowedNumberIds = null): LengthAwarePaginator
    {
        return $this->campaignRepository->paginate($companyId, $filter, $allowedNumberIds);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────
    public function show(int $id, int $companyId, ?array $allowedNumberIds = null): Campaign
    {
        $campaign = $this->campaignRepository->findById($id, $companyId, $allowedNumberIds);
        if (!$campaign) throw CampaignException::notFound();
        return $campaign;
    }

    // ─── Stats ────────────────────────────────────────────────────────────────
    public function stats(int $id, int $companyId): array
    {
        $campaign = $this->show($id, $companyId);
        $byStatus = $this->campaignRepository->countByStatus($id);
        $total    = $campaign->total_contacts ?: 1;

        // Replies attributed to this campaign's starts_at/ends_at lead-attribution window
        // (WaChatLeadAttributionService::matchWaCloudCampaign) — each is a first-time reply that
        // auto-created a lead, so this count is "how many leads came in during that period", and
        // the stage breakdown is where those leads currently stand.
        $leadsByStage = $campaign->leads()
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

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
            // which WhatsApp number this campaign sends from
            'sender'         => $campaign->waPhoneNumber
                ? ['id' => $campaign->waPhoneNumber->id, 'label' => $campaign->waPhoneNumber->label, 'display_number' => $campaign->waPhoneNumber->display_number]
                : null,
            'template_name'  => $campaign->template?->name,
            // failed contacts that can still be retried (any failed contact can be retried again)
            'resendable_failed' => \App\Models\CampaignContact::where('campaign_id', $campaign->id)->where('status', 'failed')->count(),
            'starts_at'      => $campaign->starts_at?->toIso8601String(),
            'ends_at'        => $campaign->ends_at?->toIso8601String(),
            'leads_received' => $leadsByStage->sum(),
            'leads_by_stage' => $leadsByStage,
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

        // Resolve the company from the campaign itself, not the request user —
        // this method also runs headless from the wa-cloud:dispatch-scheduled-campaigns
        // command, where there is no authenticated user.
        $company = $campaign->company;

        if (!in_array($campaign->status, self::LAUNCHABLE_STATUSES)) {
            throw CampaignException::notLaunchable($campaign->status);
        }

        // Resolve contact list
        $contacts = $this->campaignRepository->resolveContactPhones($companyId, $campaign);

        // Clean numbers at launch ("+91 98…" -> "9198…") and drop any that can't be a phone number
        // (8-15 digits), so they never become guaranteed failures.
        $before   = $contacts->count();
        $contacts = $contacts->each(fn ($c) => $c->phone = preg_replace('/\D+/', '', (string) $c->phone))
            ->filter(fn ($c) => preg_match('/^\d{8,15}$/', $c->phone))
            ->values();
        if ($contacts->count() < $before) {
            Log::warning("Campaign {$campaign->id}: skipped " . ($before - $contacts->count()) . ' contact(s) with invalid phone numbers at launch.');
        }

        if ($contacts->isEmpty()) {
            throw CampaignException::noContacts();
        }

        $total  = $contacts->count();

        $walletMode = $company->wa_config == 'wallet';
        $wallet     = $walletMode ? $this->walletService->getWallet($companyId) : null;

        if ($walletMode && $wallet->balance < $total) {
            throw CampaignException::insufficientBalance($wallet->balance, $total);
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
                // started_at follows the planned send time when one was set; launched_at
                // always records the moment the launch actually fired (click or scheduler).
                'started_at'     => $campaign->scheduled_at ?? now(),
                'launched_at'    => now(),
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

        $wallet?->refresh();

        return new LaunchResultDTO(
            totalContacts: $total,
            walletDebited: $total,
            remainingBalance: (int) ($wallet?->balance ?? 0),
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
    private const SYNC_RESEND_MAX = 5; // up to this many go out inside the request; more are queued so the request never times out

    /**
     * Resend failed contacts. $items (optional) = [['id' => campaign_contact id, 'phone' => corrected number?]]
     * limits it to those rows. Numbers are cleaned to digits (8-15). A marketing-capped number (131049) is
     * NOT pre-blocked: it is sent, and if Meta caps it again the row just shows the next available time.
     * A few numbers are sent right now, one by one; a larger selection is queued (one job per number,
     * spaced out) so the request returns immediately instead of hitting the 30s timeout.
     *
     * @return array{sent:int, failed:int, queued:int, skipped:list<string>, last_error:?string}
     */
    public function resendFailed(int $id, int $companyId, array $items = []): array
    {
        $campaign = $this->show($id, $companyId);
        $campaign->loadMissing(['company']);
        $company  = $campaign->company;
        $edits    = collect($items)->keyBy('id');

        $rows = \App\Models\CampaignContact::where('campaign_id', $campaign->id)
            ->where('status', 'failed')
            ->when($edits->isNotEmpty(), fn ($q) => $q->whereIn('id', $edits->keys()))
            ->limit(500)
            ->get();

        if ($rows->isEmpty()) { throw CampaignException::noFailedMessages(); }

        // Only wallet-mode companies pay per message from the wallet; own-account ones are billed by Meta.
        if ($company->wa_config == 'wallet') {
            $wallet = $this->walletService->getWallet($companyId);
            if ($wallet->balance < $rows->count()) {
                throw CampaignException::insufficientBalance($wallet->balance, $rows->count());
            }
        }

        $todo = []; $skipped = [];
        foreach ($rows as $row) {
            $phone = preg_replace('/\D+/', '', (string) (($edits[$row->id]['phone'] ?? null) ?: $row->phone));
            if (!preg_match('/^\d{8,15}$/', $phone)) { $skipped[] = (string) $row->phone; continue; }
            $todo[$row->id] = $phone;
        }

        $sent = 0; $failed = 0; $queued = 0; $lastError = null;

        if (count($todo) <= self::SYNC_RESEND_MAX) {
            foreach ($todo as $rowId => $phone) {
                $r = $this->resendContact($campaign->id, $rowId, $phone);
                $r['ok'] ? $sent++ : $failed++;
                $lastError = $r['error'] ?? $lastError;
            }
        } else {
            $i = 0;
            foreach ($todo as $rowId => $phone) {
                \App\Modules\Campaign\Jobs\ResendCampaignContact::dispatch($campaign->id, $rowId, $phone)
                    ->onQueue('campaigns')->delay(now()->addSeconds($i++ * 2)); // gentle on Meta and the server
                $queued++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'queued' => $queued, 'skipped' => $skipped, 'last_error' => $lastError];
    }

    /**
     * Send ONE failed contact to the Meta API now and update its row, the campaign counts and the wallet.
     *
     * @return array{ok:bool, error:?string}
     */
    public function resendContact(int $campaignId, int $contactRowId, string $phone): array
    {
        $campaign = Campaign::with(['template', 'waPhoneNumber', 'company'])->find($campaignId);
        $row = \App\Models\CampaignContact::where('campaign_id', $campaignId)->where('id', $contactRowId)->where('status', 'failed')->first();
        if (!$campaign || !$row) { return ['ok' => false, 'error' => null]; } // already handled elsewhere
        $company = $campaign->company;

        // credentials: the campaign's own number, else the company's legacy single-number setup
        $number = $campaign->waPhoneNumber;
        if ($number && $number->is_active && $number->access_token && $number->phone_number_id) {
            [$token, $phoneId] = [$number->decrypted_token, $number->phone_number_id];
        } elseif ($company->wa_phone_id && $company->wa_access_token) {
            [$token, $phoneId] = [decrypt($company->wa_access_token), $company->wa_phone_id];
        } else {
            $err = 'WhatsApp credentials are missing for this campaign.';
            $row->update(['failed_reason' => $err, 'error_type' => 'auth']);
            return ['ok' => false, 'error' => $err];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)->timeout(15)
                ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages",
                    \App\Modules\Campaign\Support\TemplateMessagePayload::build($phone, $campaign->template, $campaign->template_variables ?? []));
        } catch (\Throwable $e) {
            $err = 'Could not reach Meta: ' . mb_substr($e->getMessage(), 0, 200);
            $row->update(['phone' => $phone, 'attempts' => $row->attempts + 1, 'resent_at' => now(), 'error_type' => 'network', 'failed_reason' => $err]);
            return ['ok' => false, 'error' => $err];
        }

        if ($response->successful()) {
            $row->update([
                'phone' => $phone, 'status' => 'sent', 'attempts' => $row->attempts + 1, 'resent_at' => now(), 'sent_at' => now(),
                'wa_message_id' => $response->json('messages.0.id'), 'failed_reason' => null, 'error_type' => null, 'retry_after' => null,
            ]);
            if ($company->wa_config == 'wallet') {
                $this->walletService->debit($company->id, 1, "Resend failed — campaign #{$campaign->id}", (string) $campaign->id, 'campaign_resend');
            }
            $this->campaignRepository->updateStats($campaign->id, [
                'sent'   => DB::raw('sent + 1'),
                'failed' => DB::raw('GREATEST(failed - 1, 0)'),
            ]);
            // Show the resent message in the shared inbox; delivered / read / failed webhooks then update it by wa_message_id.
            try {
                $conv = \App\Models\WaConversation::firstOrCreate(['company_id' => $campaign->company_id, 'phone' => $phone], ['status' => 'open']);
                $conv->update(['last_message_at' => now()]);
                \App\Models\WaMessage::create([
                    'conversation_id' => $conv->id, 'company_id' => $campaign->company_id,
                    'direction' => 'outbound', 'sender_type' => 'system', 'wa_message_id' => $response->json('messages.0.id') ?: null,
                    'type' => 'template', 'content' => ['body' => '[Template] ' . ($campaign->template?->name ?? '') . ' — campaign: ' . $campaign->name],
                    'status' => 'sent', 'status_updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("Campaign {$campaign->id}: inbox log failed for {$phone}: {$e->getMessage()}");
            }
            return ['ok' => true, 'error' => null];
        }

        $code = (int) $response->json('error.code');
        if ($code === 131049) {
            $retryAfter = now()->addHours(24);
            $reason = 'WhatsApp is limiting marketing messages to this number. Retry is available from ' . $retryAfter->format('d M, h:i A') . '.';
            $type = 'frequency_capped';
        } else {
            $retryAfter = null;
            $reason = ($response->json('error.message') ?? 'Meta rejected the message.') . ($code ? " (code {$code})" : '');
            if ($details = $response->json('error.error_data.details')) { $reason .= " — {$details}"; }
            $reason = mb_substr($reason, 0, 500);
            $type = $code === 131026 ? 'not_on_whatsapp' : 'error';
        }
        $row->update([
            'phone' => $phone, 'attempts' => $row->attempts + 1, 'resent_at' => now(),
            'failed_reason' => $reason, 'error_type' => $type, 'retry_after' => $retryAfter,
        ]);
        return ['ok' => false, 'error' => $reason];
    }

    // ─── Campaign contacts ────────────────────────────────────────────────────
    public function contacts(int $id, int $companyId, CampaignContactFilterDTO $filter): LengthAwarePaginator
    {
        $campaign = $this->show($id, $companyId);
        return $this->campaignRepository->paginateContacts($campaign->id, $filter);
    }
}
