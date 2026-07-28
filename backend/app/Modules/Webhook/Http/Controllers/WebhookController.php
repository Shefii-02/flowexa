<?php

namespace App\Modules\Webhook\Http\Controllers;

use App\Models\Company;
use App\Models\WebhookLog;
use App\Modules\Webhook\DTOs\InboundMessageDTO;
use App\Modules\Webhook\DTOs\StatusUpdateDTO;
use App\Modules\Webhook\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhookService) {}

    // ─── GET: Meta verification challenge ────────────────────────────────────
    public function verify(Request $request): Response|JsonResponse
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    // ─── POST: Incoming events from Meta ─────────────────────────────────────
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $start   = microtime(true);

        // Log webhook receipt
        $log = WebhookLog::create([
            'payload'    => $payload,
            'status'     => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->processPayload($payload, $log);
        } catch (\Exception $e) {
            Log::error('Webhook processing error: ' . $e->getMessage(), ['payload' => $payload]);
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        $log->update(['processing_ms' => (int) ((microtime(true) - $start) * 1000)]);

        // Always return 200 to Meta (otherwise it will retry)
        return response()->json(['status' => 'ok']);
    }

    // ─── Process payload entries ──────────────────────────────────────────────
    private function processPayload(array $payload, WebhookLog $log): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // Resolve company by phone number ID
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                if (!$phoneNumberId) continue;

                $company = Company::where('wa_phone_id', $phoneNumberId)
                    ->where('status', 'active')
                    ->first();

                if (!$company) {
                    Log::warning("Webhook: no company found for phone_id {$phoneNumberId}");
                    continue;
                }

                $log->update(['company_id' => $company->id]);

                // ── Inbound messages ──────────────────────────────────────────
                foreach ($value['messages'] ?? [] as $message) {
                    $contact = $value['contacts'][0] ?? [];
                    $phone   = $contact['wa_id']    ?? $message['from'];
                    $waId    = $contact['wa_id']    ?? $message['from'];

                    $dto = InboundMessageDTO::fromMeta($message, $phone, $waId);
                    $this->webhookService->handleInbound($company, $dto);
                }

                // ── Status updates ────────────────────────────────────────────
                foreach ($value['statuses'] ?? [] as $status) {
                    $dto = StatusUpdateDTO::fromMeta($status);
                    $this->webhookService->handleStatusUpdate($company, $dto);
                }
            }
        }
    }
}
