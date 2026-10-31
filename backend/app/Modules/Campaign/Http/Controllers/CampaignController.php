<?php

namespace App\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campaign\DTOs\CampaignContactFilterDTO;
use App\Modules\Campaign\DTOs\CampaignFilterDTO;
use App\Modules\Campaign\DTOs\CreateCampaignDTO;
use App\Modules\Campaign\DTOs\UpdateCampaignDTO;
use App\Modules\Campaign\Http\Requests\CampaignContactFilterRequest;
use App\Modules\Campaign\Http\Requests\CampaignFilterRequest;
use App\Modules\Campaign\Http\Requests\CreateCampaignRequest;
use App\Modules\Campaign\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaign\Http\Resources\CampaignCollection;
use App\Modules\Campaign\Http\Resources\CampaignContactResource;
use App\Modules\Campaign\Http\Resources\CampaignResource;
use App\Modules\Campaign\Services\CampaignMessageSender;
use App\Modules\Campaign\Services\CampaignService;
use App\Models\WaPhoneNumber;
use App\Models\WaTemplate;
use App\Modules\Campaign\Support\TemplateMessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaignService) {}

    public function index(CampaignFilterRequest $request): JsonResponse
    {
        $paginator = $this->campaignService->list(
            auth()->user()->company_id,
            CampaignFilterDTO::fromRequest($request->validated()),
            auth()->user()->allowedAccountIds('phone_number')
        );
        return (new CampaignCollection($paginator))->response();
    }

    /**
     * Send one real template message to a single number, using the chosen template and sending
     * number, so a campaign can be verified before it is launched. Does not touch any campaign.
     */
    public function testSend(Request $request, CampaignMessageSender $sender): JsonResponse
    {
        $user = auth()->user();
        $d = $request->validate([
            'template_id'        => ['required', 'integer'],
            'wa_phone_number_id' => ['required', 'integer'],
            'phone'              => ['required', 'string', 'regex:/^\+?[\d\s\-()]{8,20}$/'],
            'variables'          => ['nullable', 'array'],
            'variables.*'        => ['nullable', 'string', 'max:255'],
            'coupon_code'        => ['nullable', 'string', 'max:15'],
        ]);

        $log  = fn (string $m, array $ctx = []) => Log::info("[campaign-test-send] {$m}", ['company' => $user->company_id, 'user' => $user->id] + $ctx);
        $deny = function (string $msg) use ($user) {
            Log::warning("[campaign-test-send] refused (422): {$msg}", ['company' => $user->company_id, 'user' => $user->id]);
            return response()->json(['message' => $msg], 422);
        };
        $log('request', ['phone' => $d['phone'], 'template_id' => $d['template_id'], 'number_id' => $d['wa_phone_number_id'], 'variables' => $d['variables'] ?? null, 'coupon' => $d['coupon_code'] ?? null]);

        $template = WaTemplate::where('id', $d['template_id'])->where('company_id', $user->company_id)->firstOrFail();
        $number   = WaPhoneNumber::where('id', $d['wa_phone_number_id'])->where('company_id', $user->company_id)->firstOrFail();

        if (strtolower($template->status) !== 'approved') {
            return $deny("Template is \"{$template->status}\" — only approved templates can be sent.");
        }
        if (!$number->is_active || !$number->access_token || !$number->phone_number_id) {
            return $deny('That sending number is inactive or has no credentials.');
        }

        $variables = array_values($d['variables'] ?? []);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $template->body, $m);
        $needed = $m[1] ? max(array_map('intval', $m[1])) : 0;
        if ($needed > count(array_filter($variables, fn ($v) => trim((string) $v) !== ''))) {
            return $deny("This template needs {$needed} variable value(s) for the test.");
        }

        $to = preg_replace('/\D+/', '', $d['phone']);
        $log('inputs resolved, sending to Meta', ['template' => $template->name, 'number' => $number->phone_number_id, 'to' => $to, 'variables' => $variables]);

        // Same sender (classification, wording, logging) as real campaign sends.
        $r = $sender->send($number->decrypted_token, $number->phone_number_id, $to, $template, $variables, $d['coupon_code'] ?? null);

        $log('Meta response', ['to' => $to, 'ok' => $r['ok'], 'wa_message_id' => $r['wa_message_id'] ?? null, 'error_type' => $r['error_type'] ?? null,
            'reason' => $r['reason'] ?? null, 'http' => $r['http'] ?? 200, 'meta_body' => $r['meta'] ?? null]);

        $body = ['body' => "[Template] {$template->name} — test send", 'template' => $template->name];
        \App\Models\MessageLog::recordCloudOutbound($user->company_id, $to, 'template', $body, 'test',
            $r['wa_message_id'], $r['ok'] ? null : ($r['reason'] ?: 'Send failed'), null, 'Test send',
            strtolower((string) $template->category), $r['retry_after']);

        $log('message log recorded', ['to' => $to, 'status' => $r['ok'] ? 'sent' : 'failed']);

        if (!$r['ok']) {
            return response()->json(['message' => $r['reason']], 422);
        }

        return response()->json([
            'message'    => "Test message sent to +{$to}.",
            'message_id' => $r['wa_message_id'],
        ]);
    }

    /**
     * POST /campaigns/retry — send one failed contact of a campaign right now (see CampaignService::retryContact).
     * Takes the same inputs as test-send plus campaign_id (and campaign_contact_id when known).
     */
    public function retryContact(Request $request): JsonResponse
    {
        $user = auth()->user();
        $d = $request->validate([
            'campaign_id'         => ['required', 'integer'],
            'campaign_contact_id' => ['nullable', 'integer'],
            'template_id'         => ['nullable', 'integer'],
            'wa_phone_number_id'  => ['nullable', 'integer'],
            'phone'               => ['required', 'string', 'regex:/^\+?[\d\s\-()]{8,20}$/'],
            'variables'           => ['nullable', 'array'],
            'variables.*'         => ['nullable', 'string', 'max:255'],
            'coupon_code'         => ['nullable', 'string', 'max:15'],
        ]);

        $res  = $this->campaignService->retryContact((int) $d['campaign_id'], $user->company_id, $d);
        $to   = preg_replace('/\D+/', '', $d['phone']);
        $body = [
            'campaign' => new CampaignResource($res['campaign']),
            'contact'  => $res['contact'] ? ['id' => $res['contact']->id, 'phone' => $res['contact']->phone, 'status' => $res['contact']->status,
                           'attempts' => $res['contact']->attempts, 'failed_reason' => $res['contact']->failed_reason, 'wa_message_id' => $res['contact']->wa_message_id] : null,
        ];

        return $res['ok']
            ? response()->json(['message' => "Retry sent to +{$to}.", 'message_id' => $res['message_id']] + $body)
            : response()->json(['message' => $res['error']] + $body, $res['http']);
    }

    public function show(int $campaign): JsonResponse
    {
        $c = $this->campaignService->show($campaign, auth()->user()->company_id, auth()->user()->allowedAccountIds('phone_number'));
        return response()->json(['campaign' => new CampaignResource($c)]);
    }

    public function stats(int $campaign): JsonResponse
    {
        $stats = $this->campaignService->stats($campaign, auth()->user()->company_id);
        return response()->json(['stats' => $stats]);
    }

    public function store(CreateCampaignRequest $request): JsonResponse
    {
        $csvPath = null;
        if ($request->hasFile('file')) {
            $csvPath = $request->file('file')->store('campaigns/' . auth()->user()->company_id, 'local');
        }

        $campaign = $this->campaignService->create(
            companyId: auth()->user()->company_id,
            userId:    auth()->id(),
            dto:       CreateCampaignDTO::fromRequest($request->validated(), $csvPath),
        );

        return response()->json(['message' => 'Campaign created as draft.', 'campaign' => new CampaignResource($campaign)], 201);
    }

    public function update(UpdateCampaignRequest $request, int $campaign): JsonResponse
    {
        // Once created only the send time, the lead start / end dates and the auto-retry switch can be changed; every other
        // field in the request is ignored (the screens show them read-only).
        $fields = array_intersect_key($request->validated(), array_flip(['scheduled_at', 'starts_at', 'ends_at', 'auto_retry']));
        $c = $this->campaignService->updateSchedule($campaign, auth()->user()->company_id, $fields);
        return response()->json(['message' => 'Campaign updated.', 'campaign' => new CampaignResource($c)]);
    }

    public function destroy(int $campaign): JsonResponse
    {
        $this->campaignService->delete($campaign, auth()->user()->company_id);
        return response()->json(['message' => 'Campaign deleted.']);
    }

    public function launch(int $campaign): JsonResponse
    {
        $result = $this->campaignService->launch($campaign, auth()->user()->company_id);
        return response()->json([
            'message'          => "Campaign launched for {$result->totalContacts} contacts.",
            'total_contacts'   => $result->totalContacts,
            'wallet_debited'   => $result->walletDebited,
            'remaining_balance'=> $result->remainingBalance,
        ]);
    }

    public function pause(int $campaign): JsonResponse
    {
        $c = $this->campaignService->pause($campaign, auth()->user()->company_id);
        return response()->json(['message' => 'Campaign paused.', 'campaign' => new CampaignResource($c)]);
    }

    public function resume(int $campaign): JsonResponse
    {
        $c = $this->campaignService->resume($campaign, auth()->user()->company_id);
        return response()->json(['message' => 'Campaign resumed.', 'campaign' => new CampaignResource($c)]);
    }

    /**
     * GET /campaigns/background-queue — what is still waiting to be sent in the background for this company:
     * retries queued behind the worker, and campaign messages not sent yet. Read from the database on every call,
     * so a refresh shows only what is still pending — anything already sent or failed drops off the list.
     */
    public function backgroundQueue(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $queuedSince = now()->subMinutes(15); // same window CampaignContact::resendable uses for "already waiting"

        $retries = \App\Models\CampaignContact::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_contacts.campaign_id')
            ->where('campaigns.company_id', $companyId)->whereNull('campaigns.deleted_at')
            ->where('campaign_contacts.status', 'failed')->where('campaign_contacts.scheduled_for', '>=', $queuedSince);

        $items = (clone $retries)->orderBy('campaign_contacts.scheduled_for')->limit(200)
            ->get(['campaign_contacts.id', 'campaign_contacts.phone', 'campaign_contacts.attempts', 'campaign_contacts.failed_reason',
                   'campaign_contacts.scheduled_for', 'campaigns.id as campaign_id', 'campaigns.name as campaign_name'])
            ->map(fn ($r) => ['id' => $r->id, 'phone' => $r->phone, 'attempts' => (int) $r->attempts, 'last_error' => $r->failed_reason,
                              'campaign_id' => $r->campaign_id, 'campaign' => $r->campaign_name, 'type' => 'retry']);

        $campaigns = \App\Models\Campaign::where('company_id', $companyId)->whereIn('status', ['running', 'scheduled', 'paused'])
            ->withCount(['contacts as pending_count' => fn ($q) => $q->where('status', 'pending')])
            ->get(['id', 'name', 'status', 'scheduled_at', 'throttle_per_minute'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'pending' => (int) $c->pending_count,
                              'scheduled_at' => $c->scheduled_at?->toIso8601String(), 'per_minute' => $c->throttle_per_minute]);

        return response()->json([
            'retries_waiting' => (clone $retries)->count(),
            'items'           => $items,
            'campaigns'       => $campaigns->filter(fn ($c) => $c['pending'] > 0 || $c['status'] === 'scheduled')->values(),
            'as_of'           => now()->toIso8601String(),
        ]);
    }

    /** Turn the automatic retry of marketing-capped failures on or off for one campaign (works at any stage). */
    public function autoRetry(int $campaign, Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean']]);
        $model = $this->campaignService->show($campaign, auth()->user()->company_id);
        $model->update(['auto_retry' => (bool) $d['enabled']]);
        Log::info('[campaign-auto-retry] ' . ($d['enabled'] ? 'enabled' : 'stopped'), ['campaign' => $campaign, 'user' => auth()->id()]);
        return response()->json(['message' => $d['enabled'] ? 'Auto-retry is on.' : 'Auto-retry stopped for this campaign.', 'auto_retry' => (bool) $d['enabled']]);
    }

    /** Resend all failed contacts, or only `items` (each optionally with a corrected phone number). */
    public function resendFailed(int $campaign, Request $request): JsonResponse
    {
        $d = $request->validate([
            'items'              => ['nullable', 'array', 'max:500'],
            'items.*.id'         => ['required_with:items', 'integer'],
            'items.*.phone'      => ['nullable', 'string', 'max:40'],
            // optional — the same inputs a single retry takes; left out, each number uses the campaign's own
            'template_id'        => ['nullable', 'integer'],
            'wa_phone_number_id' => ['nullable', 'integer'],
            'variables'          => ['nullable', 'array'],
            'variables.*'        => ['nullable', 'string', 'max:255'],
            'coupon_code'        => ['nullable', 'string', 'max:15'],
        ]);

        $overrides = array_filter(array_intersect_key($d, array_flip(['template_id', 'wa_phone_number_id', 'variables', 'coupon_code'])), fn ($v) => $v !== null && $v !== []);
        $r = $overrides
            ? $this->campaignService->retryMany($campaign, auth()->user()->company_id, $d['items'] ?? [], $overrides)
            : $this->campaignService->resendFailed($campaign, auth()->user()->company_id, $d['items'] ?? []);

        $msg = "Queued {$r['queued']} number(s) for retry, sent one by one at the campaign's send gap — their status updates here as each goes out.";
        $msg .= $r['skipped'] ? ' Skipped ' . count($r['skipped']) . ' invalid number(s).' : '';

        return response()->json([
            'message' => $msg,
            'sent'    => $r['sent'],
            'failed'  => $r['failed'],
            'queued'  => $r['queued'],
            'skipped_invalid' => $r['skipped'],
        ]);
    }
}
