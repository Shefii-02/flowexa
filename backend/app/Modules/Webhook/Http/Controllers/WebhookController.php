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
    // Supports BOTH global verify token AND per-company verify token
    public function verify(Request $request): Response|JsonResponse
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check global token first
        if ($token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        // Check per-company token (multi-tenant: each company has own token)
        $company = Company::where('webhook_verify_token', $token)
            ->whereIn('status', ['active', 'trial'])
            ->first();

        if ($company) {
            Log::info("Webhook verified for company: {$company->id} — {$company->name}");
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning("Webhook verify failed — unknown token: {$token}");
        return response()->json(['message' => 'Forbidden'], 403);
    }

    // ─── POST: Incoming events from Meta ─────────────────────────────────────
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $start   = microtime(true);

        // Log receipt immediately — before any processing
        $log = WebhookLog::create([
            'payload'    => $payload,
            'status'     => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->processPayload($payload, $log);
            $log->update(['status' => 'processed']);
        } catch (\Exception $e) {
            Log::error('Webhook processing error: ' . $e->getMessage(), [
                'trace'   => $e->getTraceAsString(),
                'payload' => $payload,
            ]);
            $log->update(['status' => 'error', 'error' => $e->getMessage()]);
        }

        $log->update(['processing_ms' => (int) ((microtime(true) - $start) * 1000)]);

        // Always 200 to Meta — never retry
        return response()->json(['status' => 'ok']);
    }

    // ─── Process all entries in payload ──────────────────────────────────────
    private function processPayload(array $payload, WebhookLog $log): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                // ── Template status events (no phone_number_id — uses WABA ID) ──
                if ($field === 'message_template_status_update') {
                    $this->handleTemplateStatus($value);
                    continue;
                }

                if ($field === 'message_template_quality_update') {
                    $this->handleTemplateQuality($value);
                    continue;
                }

                // ── Message events — resolve company by phone_number_id ────────
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                if (!$phoneNumberId) continue;

                $company = $this->resolveCompany($phoneNumberId);

                if (!$company) {
                    Log::warning("Webhook: no active company for phone_number_id={$phoneNumberId}");
                    $log->update(['error' => "No company found for phone_number_id: {$phoneNumberId}"]);
                    continue;
                }

                $log->update(['company_id' => $company->id]);

                // ── Inbound messages ──────────────────────────────────────────
                foreach ($value['messages'] ?? [] as $message) {
                    $contact = $value['contacts'][0] ?? [];
                    $phone   = $contact['wa_id'] ?? $message['from'];
                    $waId    = $contact['wa_id'] ?? $message['from'];

                    $dto = InboundMessageDTO::fromMeta($message, $phone, $waId);
                    $this->webhookService->handleInbound($company, $dto);
                }

                // ── Delivery / read status updates ────────────────────────────
                foreach ($value['statuses'] ?? [] as $status) {
                    $dto = StatusUpdateDTO::fromMeta($status);
                    $this->webhookService->handleStatusUpdate($company, $dto);
                }
            }
        }
    }

    // ─── Resolve company by phone_number_id ──────────────────────────────────
    // Checks wa_phone_numbers table (V2 multi-number) first,
    // then falls back to companies.wa_phone_id (V1 single number)
    private function resolveCompany(string $phoneNumberId): ?Company
    {
        // V2: multi-number table
        $phoneRecord = \App\Models\WaPhoneNumber::where('phone_number_id', $phoneNumberId)
            ->with('company')
            ->first();

        if ($phoneRecord?->company && in_array($phoneRecord->company->status, ['active', 'trial'])) {
            return $phoneRecord->company;
        }

        // V1: fallback — single phone on company
        return Company::where('wa_phone_id', $phoneNumberId)
            ->whereIn('status', ['active', 'trial'])
            ->first();
    }

    // ─── Template APPROVED / REJECTED webhook ────────────────────────────────
    private function handleTemplateStatus(array $value): void
    {
        $templateName = $value['message_template_name'] ?? null;
        $event        = $value['event']                 ?? null; // APPROVED | REJECTED | PENDING_DELETION | FLAGGED
        $reason       = $value['reason']                ?? null;
        $wabaId       = $value['account_id']            ?? null;

        Log::info("Template status update: name={$templateName} event={$event}");

        if (!$templateName || !$event) return;

        // Find template — match by name + WABA if available
        $query = WaTemplate::where('name', $templateName);

        if ($wabaId) {
            // Match via company's WABA id
            $query->whereHas('company', fn($q) => $q->where('wa_business_account_id', $wabaId));
        }

        $template = $query->first();

        if (!$template) {
            Log::warning("Template webhook: no template found for name={$templateName}");
            return;
        }

        $statusMap = [
            'APPROVED'         => 'approved',
            'REJECTED'         => 'rejected',
            'PENDING_DELETION' => 'pending_deletion',
            'FLAGGED'          => 'flagged',
            'PAUSED'           => 'paused',
        ];

        $template->update([
            'status'           => $statusMap[$event] ?? strtolower($event),
            'rejection_reason' => in_array($event, ['REJECTED', 'FLAGGED'])
                ? ($reason ?? 'Rejected by Meta — check template content guidelines')
                : null,
        ]);

        // Push notification to company owner
        try {
            app(\App\Services\FirebasePushService::class)->notifyCompany($template->company_id, [
                'type'  => 'template_status',
                'title' => "Template {$event}",
                'body'  => "'{$templateName}' " . ($event === 'APPROVED'
                    ? 'was approved and is ready to use in campaigns.'
                    : "was {$event}" . ($reason ? ": {$reason}" : '.')),
            ]);
        } catch (\Exception $e) {
            Log::warning('Push notification failed for template status: ' . $e->getMessage());
        }
    }

    // ─── Template quality update ──────────────────────────────────────────────
    private function handleTemplateQuality(array $value): void
    {
        $templateName    = $value['message_template_name'] ?? null;
        $qualityScore    = $value['quality_score']         ?? null; // RED | YELLOW | GREEN
        $previousQuality = $value['previous_quality_score'] ?? null;

        if (!$templateName || !$qualityScore) return;

        $template = WaTemplate::where('name', $templateName)->first();
        if (!$template) return;

        $template->update(['quality_score' => strtolower($qualityScore)]);

        // Warn on quality drop
        if ($qualityScore === 'RED' || $qualityScore === 'YELLOW') {
            try {
                app(\App\Services\FirebasePushService::class)->notifyCompany($template->company_id, [
                    'type'  => 'template_quality',
                    'title' => 'Template quality alert',
                    'body'  => "'{$templateName}' quality dropped to {$qualityScore}. Too many users are blocking/reporting it.",
                ]);
            } catch (\Exception $e) {
                Log::warning('Push failed for template quality: ' . $e->getMessage());
            }
        }
    }

    // public function __construct(private readonly WebhookService $webhookService) {}

    // // ─── GET: Meta verification challenge ────────────────────────────────────
    // public function verify(Request $request): Response|JsonResponse
    // {
    //     $mode      = $request->query('hub_mode');
    //     $token     = $request->query('hub_verify_token');
    //     $challenge = $request->query('hub_challenge');

    //     if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
    //         return response($challenge, 200)->header('Content-Type', 'text/plain');
    //     }

    //     return response()->json(['message' => 'Forbidden'], 403);
    // }

    // // ─── POST: Incoming events from Meta ─────────────────────────────────────
    // public function handle(Request $request): JsonResponse
    // {
    //     $payload = $request->all();
    //     $start   = microtime(true);

    //     // Log webhook receipt
    //     $log = WebhookLog::create([
    //         'payload'    => $payload,
    //         'status'     => 'processed',
    //         'created_at' => now(),
    //         'updated_at' => now(),
    //     ]);

    //     try {
    //         $this->processPayload($payload, $log);
    //     } catch (\Exception $e) {
    //         Log::error('Webhook processing error: ' . $e->getMessage(), ['payload' => $payload]);
    //         $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
    //     }

    //     $log->update(['processing_ms' => (int) ((microtime(true) - $start) * 1000)]);

    //     // Always return 200 to Meta (otherwise it will retry)
    //     return response()->json(['status' => 'ok']);
    // }

    // // ─── Process payload entries ──────────────────────────────────────────────
    // private function processPayload(array $payload, WebhookLog $log): void
    // {
    //     foreach ($payload['entry'] ?? [] as $entry) {
    //         foreach ($entry['changes'] ?? [] as $change) {
    //             $value = $change['value'] ?? [];

    //             // Resolve company by phone number ID
    //             $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
    //             if (!$phoneNumberId) continue;

    //             $company = Company::where('wa_phone_id', $phoneNumberId)
    //                 ->where('status', 'active')
    //                 ->first();

    //             if (!$company) {
    //                 Log::warning("Webhook: no company found for phone_id {$phoneNumberId}");
    //                 continue;
    //             }

    //             $log->update(['company_id' => $company->id]);

    //             // ── Inbound messages ──────────────────────────────────────────
    //             foreach ($value['messages'] ?? [] as $message) {
    //                 $contact = $value['contacts'][0] ?? [];
    //                 $phone   = $contact['wa_id']    ?? $message['from'];
    //                 $waId    = $contact['wa_id']    ?? $message['from'];

    //                 $dto = InboundMessageDTO::fromMeta($message, $phone, $waId);
    //                 $this->webhookService->handleInbound($company, $dto);
    //             }

    //             // ── Status updates ────────────────────────────────────────────
    //             foreach ($value['statuses'] ?? [] as $status) {
    //                 $dto = StatusUpdateDTO::fromMeta($status);
    //                 $this->webhookService->handleStatusUpdate($company, $dto);
    //             }
    //         }
    //     }
    // }
}
