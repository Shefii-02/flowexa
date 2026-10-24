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
        private readonly CampaignMessageSender       $sender,
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
        // from the real contact rows, not the stored counter columns
        $read      = (int) ($byStatus['read'] ?? 0);
        $delivered = $read + (int) ($byStatus['delivered'] ?? 0);
        $sent      = $delivered + (int) ($byStatus['sent'] ?? 0);
        $failed    = (int) ($byStatus['failed'] ?? 0);

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
            'sent'           => $sent,
            'delivered'      => $delivered,
            'read'           => $read,
            'failed'         => $failed,
            'pending'        => (int) ($byStatus['pending'] ?? 0),
            'wallet_debited' => $campaign->wallet_debited,
            'delivery_rate'  => round(($delivered / $total) * 100, 1),
            'read_rate'      => round(($read / $total) * 100, 1),
            'fail_rate'      => round(($failed / $total) * 100, 1),
            'by_status'      => $byStatus,
            // which WhatsApp number this campaign sends from
            'sender'         => $campaign->waPhoneNumber
                ? ['id' => $campaign->waPhoneNumber->id, 'label' => $campaign->waPhoneNumber->label, 'display_number' => $campaign->waPhoneNumber->display_number]
                : null,
            'template_name'  => $campaign->template?->name,
            // failed contacts that can still be retried (any failed contact can be retried again)
            'resendable_failed' => \App\Models\CampaignContact::resendable($campaign->id)->count(),
            'starts_at'      => $campaign->starts_at?->toIso8601String(),
            'ends_at'        => $campaign->ends_at?->toIso8601String(),
            // customers who answered this campaign (a reply after it was sent to them) and how many reply messages that is
            ...(function () use ($campaign, $companyId, $sent) {
                $r = $this->campaignRepository->replyTotals($campaign->id, $companyId);
                return ['responded' => $r['responded'], 'responses' => $r['responses'], 'response_rate' => $sent ? round($r['responded'] / $sent * 100, 1) : 0];
            })(),
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

    // ─── Retry (one number now, or many through the queue) ────────────────────
    /**
     * The template, number, variables and coupon for a retry, checked the way a test send is: template approved,
     * number active with credentials, every {{n}} filled. Anything not given falls back to the campaign's own.
     *
     * @param array{template_id?:int|null, wa_phone_number_id?:int|null, variables?:array|null, coupon_code?:string|null} $in
     * @return array{0:?array{template:\App\Models\WaTemplate, number:\App\Models\WaPhoneNumber, variables:array, coupon:?string}, 1:?string}  [inputs, error]
     */
    private function retryInputs(Campaign $campaign, int $companyId, array $in): array
    {
        // show() loads the template / number with only a few columns — a send needs the full records
        $template = \App\Models\WaTemplate::where('company_id', $companyId)->find(!empty($in['template_id']) ? (int) $in['template_id'] : $campaign->template_id);
        $number   = \App\Models\WaPhoneNumber::where('company_id', $companyId)->find(!empty($in['wa_phone_number_id']) ? (int) $in['wa_phone_number_id'] : $campaign->wa_phone_number_id);
        if (!$template) { return [null, 'Template not found.']; }
        if (strtolower((string) $template->status) !== 'approved') { return [null, "Template is \"{$template->status}\" — only approved templates can be sent."]; }
        if (!$number || !$number->is_active || !$number->access_token || !$number->phone_number_id) { return [null, 'That sending number is inactive or has no credentials.']; }

        $variables = array_values(array_key_exists('variables', $in) && $in['variables'] !== null ? $in['variables'] : ($campaign->template_variables ?? []));
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $template->body, $m);
        $needed = $m[1] ? max(array_map('intval', $m[1])) : 0;
        if ($needed > count(array_filter($variables, fn ($v) => trim((string) $v) !== ''))) {
            return [null, "This template needs {$needed} variable value(s)."];
        }
        return [['template' => $template, 'number' => $number, 'variables' => $variables, 'coupon' => $in['coupon_code'] ?? null], null];
    }

    /**
     * Send one retry and record it everywhere: the contact's row, its single message-log row (source "campaign",
     * this campaign), the campaign's counters and the wallet. The result Meta reports later lands on the same row.
     *
     * @return array{ok:bool, wa_message_id:?string, reason:?string}
     */
    private function deliverRetry(Campaign $campaign, \App\Models\CampaignContact $row, string $to, array $inputs, \App\Models\Company $company): array
    {
        $campaign->setRelation('template', $inputs['template']); // the log shows the template that was actually sent
        $r = $this->sender->send($inputs['number']->decrypted_token, $inputs['number']->phone_number_id, $to, $inputs['template'], $inputs['variables'], $inputs['coupon']);

        Log::info('[campaign-retry] Meta response', ['campaign' => $campaign->id, 'row' => $row->id, 'to' => $to, 'ok' => $r['ok'], 'wa_message_id' => $r['wa_message_id'] ?? null,
            'error_type' => $r['error_type'] ?? null, 'reason' => $r['reason'] ?? null, 'http' => $r['http'] ?? 200, 'meta_body' => $r['meta'] ?? null]);

        $this->sender->record($campaign, $row, $to, $r, $row->attempts + 1, true);
        Log::info('[campaign-retry] row + message log updated', ['campaign' => $campaign->id, 'row' => $row->id, 'status' => $r['ok'] ? 'sent' : 'failed']);

        if ($r['ok']) {
            if ($this->walletService->usesWallet($company)) {
                $this->walletService->debit($campaign->company_id, 1, "Retry — campaign #{$campaign->id}", (string) $campaign->id, 'campaign_resend');
            }
            $this->campaignRepository->updateStats($campaign->id, [
                'sent'   => DB::raw('sent + 1'),
                'failed' => DB::raw('IF(failed > 0, failed - 1, 0)'), // `failed` is unsigned: failed - 1 would error at 0
            ]);
            Log::info('[campaign-retry] counters updated (sent +1, failed -1), wallet debited if wallet billing', ['campaign' => $campaign->id]);
        }
        return $r;
    }

    /**
     * Send ONE failed contact of a campaign immediately — same inputs as the test send (template, number,
     * variables, coupon) but tied to the campaign, so analytics and the message log show it as part of the
     * campaign (never as a "Test send"). Anything left out falls back to the campaign's own settings.
     *
     * @param array{campaign_contact_id?:int|null, phone:string, template_id?:int|null, wa_phone_number_id?:int|null, variables?:array|null, coupon_code?:string|null} $in
     * @return array{ok:bool, http:int, error:?string, message_id:?string, contact:?\App\Models\CampaignContact, campaign:Campaign}
     */
    public function retryContact(int $campaignId, int $companyId, array $in): array
    {
        $campaign = $this->show($campaignId, $companyId);
        $company  = \App\Models\Company::find($companyId);
        Log::info('[campaign-retry] request', ['campaign' => $campaignId, 'company' => $companyId, 'contact_row' => $in['campaign_contact_id'] ?? null, 'phone' => $in['phone'] ?? null,
            'template_id' => $in['template_id'] ?? null, 'number_id' => $in['wa_phone_number_id'] ?? null, 'variables' => $in['variables'] ?? null, 'coupon' => $in['coupon_code'] ?? null]);
        $fail = function (string $msg, int $http = 422, $row = null) use ($campaign, $campaignId) {
            Log::warning("[campaign-retry] refused ({$http}): {$msg}", ['campaign' => $campaignId]);
            return ['ok' => false, 'http' => $http, 'error' => $msg, 'message_id' => null, 'contact' => $row, 'campaign' => $campaign];
        };

        $to = preg_replace('/\D+/', '', (string) $in['phone']);
        if (strlen($to) < 8) { return $fail('Enter a valid number with the country code.'); }

        // the failed row: by its id when given (so a corrected number still finds it), else by the number
        $base = \App\Models\CampaignContact::resendable($campaign->id);
        $row = !empty($in['campaign_contact_id'])
            ? (clone $base)->where('id', (int) $in['campaign_contact_id'])->first()
            : (clone $base)->whereIn('phone', [$to, '+' . $to])->first();
        if (!$row) { return $fail('There is no failed message to retry for that number in this campaign — it may already be sent or waiting in the retry queue.'); }

        Log::info('[campaign-retry] failed row found', ['campaign' => $campaignId, 'row' => $row->id, 'phone' => $row->phone, 'attempts' => $row->attempts, 'reason' => $row->failed_reason]);

        [$inputs, $error] = $this->retryInputs($campaign, $companyId, $in);
        if ($error) { return $fail($error); }
        Log::info('[campaign-retry] inputs resolved', ['template' => $inputs['template']->name, 'number' => $inputs['number']->phone_number_id, 'variables' => $inputs['variables'], 'coupon' => $inputs['coupon']]);

        if ($this->walletService->usesWallet($company) && $this->walletService->getWallet($companyId)->balance < 1) {
            return $fail('Not enough wallet balance to send this message.', 402);
        }

        // Claim the row first, so a second click (or the auto-retry job) cannot send it twice.
        $claimed = \App\Models\CampaignContact::where('id', $row->id)->where('status', 'failed')
            ->where(fn ($w) => $w->whereNull('scheduled_for')->orWhere('scheduled_for', '<', now()->subMinutes(15)))
            ->update(['scheduled_for' => now()]);
        if (!$claimed) { return $fail('That message is already being retried.', 409, $row); }
        $row->refresh(); // so the in-memory row matches the claim, and record() really clears scheduled_for
        Log::info('[campaign-retry] row claimed, sending to Meta', ['campaign' => $campaignId, 'row' => $row->id, 'to' => $to]);

        $r = $this->deliverRetry($campaign, $row, $to, $inputs, $company);

        return ['ok' => (bool) $r['ok'], 'http' => $r['ok'] ? 200 : 422, 'error' => $r['ok'] ? null : ($r['reason'] ?: 'Send failed'),
                'message_id' => $r['wa_message_id'] ?? null, 'contact' => $row->fresh(), 'campaign' => $campaign->fresh(['template'])];
    }

    /**
     * Several failed numbers (or all of them): each becomes its own queue job, spaced by the campaign's send
     * gap, sent with the same template / number / variables / coupon rules as a single retry. The inputs are
     * checked once up front, so a bad template or missing variable is refused before anything is queued.
     */
    public function retryMany(int $campaignId, int $companyId, array $items, array $in): array
    {
        $campaign = $this->show($campaignId, $companyId);
        [, $error] = $this->retryInputs($campaign, $companyId, $in);
        if ($error) { throw \Illuminate\Validation\ValidationException::withMessages(['template_id' => [$error]]); }
        $overrides = array_intersect_key($in, array_flip(['template_id', 'wa_phone_number_id', 'variables', 'coupon_code']));
        return $this->resendFailed($campaignId, $companyId, $items, $overrides);
    }

    // ─── Update schedule ──────────────────────────────────────────────────────
    /**
     * The only things that can change on a campaign that has not started: when it sends, and the lead
     * window (replies arriving between starts_at and ends_at are credited to it). Everything else —
     * name, template, number, audience, speed, limits — is fixed once the campaign is created.
     * A key present with a null value CLEARS that date (a cleared send time puts the campaign back to
     * "draft", to be launched by hand); a key that is absent is left alone.
     */
    public function updateSchedule(int $id, int $companyId, array $fields): Campaign
    {
        $campaign = $this->show($id, $companyId);

        if (!in_array($campaign->status, self::EDITABLE_STATUSES)) {
            throw CampaignException::notEditable($campaign->status);
        }

        $data = [];
        if (array_key_exists('scheduled_at', $fields)) {
            $data['scheduled_at'] = $fields['scheduled_at']
                ? \Illuminate\Support\Carbon::parse($fields['scheduled_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
                : null;
            $data['status'] = $data['scheduled_at'] ? 'scheduled' : 'draft';
        }
        if (array_key_exists('starts_at', $fields)) { $data['starts_at'] = $fields['starts_at'] ?: null; }
        if (array_key_exists('ends_at', $fields))   { $data['ends_at']   = $fields['ends_at']   ?: null; }
        if (array_key_exists('auto_retry', $fields) && $fields['auto_retry'] !== null) { $data['auto_retry'] = (bool) $fields['auto_retry']; }

        $starts = array_key_exists('starts_at', $data) ? $data['starts_at'] : $campaign->starts_at;
        $ends   = array_key_exists('ends_at', $data)   ? $data['ends_at']   : $campaign->ends_at;
        if ($starts && $ends && \Illuminate\Support\Carbon::parse($ends)->lt(\Illuminate\Support\Carbon::parse($starts)->startOfDay())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ends_at' => ['The lead end date must be on or after the start date.']]);
        }

        if ($data) { $campaign->update($data); }

        return $campaign->fresh(['creator', 'template']);
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

        $walletMode = $this->walletService->usesWallet($company);
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


            if ($this->walletService->usesWallet($company)) {
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
    /**
     * Retry failed contacts. $items (optional) = [['id' => campaign_contact id, 'phone' => corrected number?]]
     * limits it to those rows. Numbers are cleaned to digits (8-15). Nothing is sent inside the request:
     * every number becomes its own queue job, delayed by the campaign's send gap (60 / throttle_per_minute
     * seconds), and goes through the same sender as test-send and the campaign jobs. A marketing-capped
     * number (131049) is not pre-blocked: if Meta caps it again the row just shows the next available time.
     *
     * @return array{sent:int, failed:int, queued:int, skipped:list<string>, last_error:?string}
     */
    public function resendFailed(int $id, int $companyId, array $items = [], array $overrides = []): array
    {
        $campaign = $this->show($id, $companyId);
        $campaign->loadMissing(['company']);
        $company  = $campaign->company;
        $edits    = collect($items)->keyBy('id');

        // not already waiting in the queue (a double click must not queue the same number twice)
        $rows = \App\Models\CampaignContact::resendable($campaign->id)
            ->when($edits->isNotEmpty(), fn ($q) => $q->whereIn('id', $edits->keys()))
            ->limit(500)
            ->get(['id', 'phone']); // only what is needed — this runs inside a web request

        if ($rows->isEmpty()) { throw CampaignException::noFailedMessages(); }

        // Only wallet-mode companies pay per message from the wallet; own-account ones are billed by Meta.
        if ($this->walletService->usesWallet($company)) {
            $wallet = $this->walletService->getWallet($companyId);
            if ($wallet->balance < $rows->count()) {
                throw CampaignException::insufficientBalance($wallet->balance, $rows->count());
            }
        }

        $gap = 60 / max(1, (int) $campaign->throttle_per_minute);
        $skipped = []; $jobs = [];

        foreach ($rows as $row) {
            $phone = preg_replace('/\D+/', '', (string) (($edits[$row->id]['phone'] ?? null) ?: $row->phone));
            if (!preg_match('/^\d{8,15}$/', $phone)) { $skipped[] = (string) $row->phone; continue; }
            $jobs[] = [$row->id, $phone, now()->addSeconds((int) floor(count($jobs) * $gap))];
        }

        // ONE update marks every queued number (it stays marked until the last job is due + 15 min, so a second
        // click cannot queue them again) — a row-by-row update made a 500-number click take long enough to time out.
        if ($jobs) {
            \App\Models\CampaignContact::whereIn('id', array_column($jobs, 0))->update(['scheduled_for' => end($jobs)[2]]);
            foreach ($jobs as [$rowId, $phone, $at]) {
                \App\Modules\Campaign\Jobs\ResendCampaignContact::dispatch($campaign->id, $rowId, $phone, $overrides)->onQueue('campaigns')->delay($at);
            }
        }
        $queued = count($jobs);

        Log::info("[campaign-retry] campaign {$campaign->id}: queued {$queued} retry job(s), gap " . round($gap, 2) . 's, skipped ' . count($skipped) . ' invalid');

        return ['sent' => 0, 'failed' => 0, 'queued' => $queued, 'skipped' => $skipped, 'last_error' => null];
    }

    /**
     * Send ONE failed contact to the Meta API now and update its row, the campaign counts and the wallet.
     *
     * @return array{ok:bool, error:?string}
     */
    public function resendContact(int $campaignId, int $contactRowId, string $phone, array $overrides = []): array
    {
        $campaign = Campaign::with(['template', 'waPhoneNumber', 'company'])->find($campaignId);
        $row = \App\Models\CampaignContact::where('campaign_id', $campaignId)->where('id', $contactRowId)->where('status', 'failed')->first();
        if (!$campaign || !$row) { return ['ok' => false, 'error' => null]; } // already handled elsewhere

        // A retry that carries its own template / number / variables / coupon goes through the shared retry path.
        if ($overrides) {
            [$inputs, $error] = $this->retryInputs($campaign, $campaign->company_id, $overrides);
            if ($inputs) {
                $r = $this->deliverRetry($campaign, $row, $phone, $inputs, \App\Models\Company::find($campaign->company_id));
                return ['ok' => (bool) $r['ok'], 'error' => $r['ok'] ? null : ($r['reason'] ?: 'Send failed')];
            }
            $r = ['ok' => false, 'wa_message_id' => null, 'error_type' => 'validation', 'raw' => null, 'retry_after' => null, 'reason' => $error];
            $this->sender->record($campaign, $row, $phone, $r, $row->attempts + 1, true);
            return ['ok' => false, 'error' => $error];
        }

        $creds = $this->sender->credentials($campaign);
        if (!$creds) {
            $r = ['ok' => false, 'wa_message_id' => null, 'error_type' => 'auth', 'raw' => null, 'retry_after' => null,
                  'reason' => 'WhatsApp credentials are missing for this campaign.'];
        } else {
            $r = $this->sender->send($creds[0], $creds[1], $phone, $campaign->template, $campaign->template_variables ?? []);
        }

        // Same campaign_contacts row, same message-log row: updated in place, never a new one.
        $this->sender->record($campaign, $row, $phone, $r, $row->attempts + 1, true);

        if ($r['ok']) {
            if ($this->walletService->usesWallet($campaign->company)) {
                $this->walletService->debit($campaign->company_id, 1, "Resend failed — campaign #{$campaign->id}", (string) $campaign->id, 'campaign_resend');
            }
            $this->campaignRepository->updateStats($campaign->id, [
                'sent'   => DB::raw('sent + 1'),
                'failed' => DB::raw('IF(failed > 0, failed - 1, 0)')  /* `failed` is unsigned: failed - 1 would error at 0 even inside GREATEST */,
            ]);
        }

        return ['ok' => $r['ok'], 'error' => $r['ok'] ? null : $r['reason']];
    }

    // ─── Campaign contacts ────────────────────────────────────────────────────
    public function contacts(int $id, int $companyId, CampaignContactFilterDTO $filter): LengthAwarePaginator
    {
        $campaign = $this->show($id, $companyId);
        return $this->campaignRepository->paginateContacts($campaign->id, $filter);
    }
}
