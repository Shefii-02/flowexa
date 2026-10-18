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
use App\Modules\Campaign\Services\CampaignService;
use App\Models\WaPhoneNumber;
use App\Models\WaTemplate;
use App\Modules\Campaign\Support\TemplateMessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
    public function testSend(Request $request): JsonResponse
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

        $template = WaTemplate::where('id', $d['template_id'])->where('company_id', $user->company_id)->firstOrFail();
        $number   = WaPhoneNumber::where('id', $d['wa_phone_number_id'])->where('company_id', $user->company_id)->firstOrFail();

        if (strtolower($template->status) !== 'approved') {
            return response()->json(['message' => "Template is \"{$template->status}\" — only approved templates can be sent."], 422);
        }
        if (!$number->is_active || !$number->access_token || !$number->phone_number_id) {
            return response()->json(['message' => 'That sending number is inactive or has no credentials.'], 422);
        }

        $variables = array_values($d['variables'] ?? []);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $template->body, $m);
        $needed = $m[1] ? max(array_map('intval', $m[1])) : 0;
        if ($needed > count(array_filter($variables, fn ($v) => trim((string) $v) !== ''))) {
            return response()->json(['message' => "This template needs {$needed} variable value(s) for the test."], 422);
        }

        $to = preg_replace('/\D+/', '', $d['phone']);

        try {
            $response = Http::withToken($number->decrypted_token)->timeout(15)
                ->post("https://graph.facebook.com/v21.0/{$number->phone_number_id}/messages",
                    TemplateMessagePayload::build($to, $template, $variables, $d['coupon_code'] ?? null));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['message' => 'Timed out contacting Meta. Try again.'], 422);
        }

        if (!$response->successful()) {
            if ((int) $response->json('error.code') === 131049) {
                return response()->json(['message' => 'WhatsApp is limiting marketing messages to this number. Retry is available from ' . now()->addHours(24)->format('d M, h:i A') . '.'], 422);
            }
            $error = $response->json('error.message') ?? 'Meta rejected the message.';
            \App\Models\MessageLog::recordCloudOutbound($user->company_id, $to, 'template', ['body' => "[Template] {$template->name} — test send", 'template' => $template->name], 'test', null, $error, null, 'Test send', strtolower((string) $template->category));
            if ($details = $response->json('error.error_data.details')) {
                $error .= " — {$details}";
            }
            return response()->json(['message' => $error], 422);
        }

        \App\Models\MessageLog::recordCloudOutbound($user->company_id, $to, 'template', ['body' => "[Template] {$template->name} — test send", 'template' => $template->name], 'test', $response->json('messages.0.id'), null, null, 'Test send', strtolower((string) $template->category));

        return response()->json([
            'message'    => "Test message sent to +{$to}.",
            'message_id' => $response->json('messages.0.id'),
        ]);
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
        $c = $this->campaignService->update($campaign, auth()->user()->company_id, UpdateCampaignDTO::fromRequest($request->validated()));
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

    /** Resend all failed contacts, or only `items` (each optionally with a corrected phone number). */
    public function resendFailed(int $campaign, Request $request): JsonResponse
    {
        $d = $request->validate([
            'items'         => ['nullable', 'array', 'max:500'],
            'items.*.id'    => ['required_with:items', 'integer'],
            'items.*.phone' => ['nullable', 'string', 'max:40'],
        ]);

        $r = $this->campaignService->resendFailed($campaign, auth()->user()->company_id, $d['items'] ?? []);

        $msg = $r['queued']
            ? "Sending {$r['queued']} number(s) in the background — their status updates here within a minute or two."
            : "Meta accepted {$r['sent']}, rejected {$r['failed']}."
                . ($r['sent'] ? ' Delivery result follows in a few seconds — capped numbers can still turn back to failed.' : '')
                . (!$r['sent'] && $r['last_error'] ? ' ' . $r['last_error'] : '');
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
