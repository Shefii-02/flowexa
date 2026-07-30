<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Plan;
use App\Models\WebhookLog;
use App\Modules\Auth\Http\Resources\CompanyResource;
use App\Modules\Settings\DTOs\MessageLogFilterDTO;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use App\Modules\Settings\Http\Requests\SuperAdminCreateCompanyRequest;
use App\Modules\Settings\Http\Requests\TopUpRequest;
use App\Modules\Settings\Http\Requests\UpdateCompanyStatusRequest;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Http\Requests\WaCredentialsRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SuperAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ─── Settings Controller ──────────────────────────────────────────────────────
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settingsService) {}

    public function index(): JsonResponse
    {
        $company = $this->settingsService->getCompany(auth()->user()->company_id);
        return response()->json(['company' => new CompanyResource($company)]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $company = $this->settingsService->update(
            auth()->user()->company,
            UpdateSettingsDTO::fromRequest($request->validated())
        );
        return response()->json(['message' => 'Settings updated.', 'company' => $company]);
    }

    public function updateWaCredentials(WaCredentialsRequest $request): JsonResponse
    {
        $this->settingsService->updateWaCredentials(
            auth()->user()->company,
            WaCredentialsDTO::fromRequest($request->validated())
        );
        return response()->json(['message' => 'WhatsApp credentials updated.', 'connected' => true]);
    }

    public function regenerateToken(): JsonResponse
    {
        $token = $this->settingsService->regenerateToken(auth()->user()->company);
        return response()->json([
            'message'       => 'Token regenerated. Store it safely — shown only once.',
            'private_token' => $token,
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $url = $this->settingsService->uploadLogo(auth()->user()->company, $request->file('logo'));
        return response()->json(['message' => 'Logo uploaded.', 'logo_url' => $url]);
    }

    public function getOtpCredentials(): JsonResponse
    {
        $credentials = $this->settingsService->getOtpCredentials(auth()->user()->company);

        return response()->json(['otp_credentials' => $credentials]);
    }


    // ── Verify WhatsApp connection ────────────────────────────────────────
    // GET /api/v1/settings/verify-wa
    // public function verifyWa(): JsonResponse
    // {
    //     $company = auth()->user()->company;

    //     if (empty($company->wa_phone_id) || empty($company->decrypt_wa_access_token)) {
    //         return response()->json([
    //             'connected' => false,
    //             'error' => 'Phone Number ID or Access Token is missing.'
    //         ], 422);
    //     }

    //     try {
    //         Log::info("Processing");
    //         $apiVersion = 'v25.0';

    //         $response = Http::withToken($company->decrypt_wa_access_token)
    //             ->acceptJson()
    //             ->timeout(15)
    //             ->get("https://graph.facebook.com/{$apiVersion}/{$company->wa_phone_id}", [
    //                 'fields' => implode(',', [
    //                     'id',
    //                     'display_phone_number',
    //                     'verified_name',
    //                     'quality_rating',
    //                     'account_mode',
    //                     'messaging_limit_tier',
    //                     'is_official_business_account'
    //                 ])
    //             ]);

    //         if (!$response->successful()) {
    //             $error = $response->json('error');
    //             $code = $error['code'] ?? null;

    //             $hint = match ($code) {
    //                 190 => 'Access Token is invalid or expired.',
    //                 100 => 'Invalid Phone Number ID or unsupported field.',
    //                 10  => 'Missing required permissions (whatsapp_business_management / whatsapp_business_messaging).',
    //                 200 => 'The token does not have permission to access this WhatsApp Business Account.',
    //                 368 => 'This WhatsApp account has been restricted by Meta.',
    //                 default => null,
    //             };

    //             return response()->json([
    //                 'connected' => false,
    //                 'error' => $error['message'] ?? 'Meta API Error',
    //                 'details' => $error['error_data']['details'] ?? null,
    //                 'error_code' => $code,
    //                 'hint' => $hint,
    //             ], $response->status());
    //         }



    //         $data = $response->json();

    //         $company->update([
    //             'wa_connected'      => true,
    //             'last_verified_at'  => now(),
    //         ]);

    //         return response()->json([
    //             'connected' => true,
    //             'phone_id' => $data['id'] ?? null,
    //             'phone_number' => $data['display_phone_number'] ?? null,
    //             'verified_name' => $data['verified_name'] ?? null,
    //             'quality_rating' => $data['quality_rating'] ?? null,
    //             'account_mode' => $data['account_mode'] ?? null,
    //             'messaging_limit_tier' => $data['messaging_limit_tier'] ?? null,
    //             'is_official_business_account' => $data['is_official_business_account'] ?? false,
    //             'verified_at' => now()->toDateTimeString(),
    //         ]);
    //     } catch (\Throwable $e) {

    //         return response()->json([
    //             'connected' => false,
    //             'error' => 'Unable to connect to Meta Graph API.',
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }
     public function verifyWa(): JsonResponse
    {
        $company = auth()->user()->company;


        if (!$company->wa_phone_id || !$company->wa_access_token) {
            return response()->json([
                'connected' => false,
                'error'     => 'Phone Number ID or Access Token is not set. Please save credentials first.',
            ]);
        }

        try {
            $response = Http::withToken($company->wa_access_token)
                ->timeout(10)
                ->get("https://graph.facebook.com/v25.0/{$company->wa_phone_id}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating,account_mode,messaging_limit_tier,is_official_business_account',
                ]);

            if ($response->failed()) {
                $err = $response->json('error.message') ?? 'Meta API returned an error.';
                $code = $response->json('error.code');

                // Common error codes
                $hint = match ($code) {
                    190  => 'Access token is invalid or expired. Use a permanent system user token.',
                    100  => 'Phone Number ID is incorrect. Check in Meta Developer Console → WhatsApp → API Setup.',
                    10   => 'App does not have permission. Make sure whatsapp_business_messaging permission is approved.',
                    368  => 'Account is temporarily blocked by Meta for policy violations.',
                    default => null,
                };

                return response()->json([
                    'connected' => false,
                    'error'     => $err . ($hint ? " Hint: {$hint}" : ''),
                    'error_code' => $code,
                ]);
            }

            $data = $response->json();

            // Update company wa_connected flag
            $company->update([
                'wa_connected' => true,
                'last_verified_at' => now(), // add this column if needed
            ]);

            return response()->json([
                'connected'              => true,
                'phone_number'           => $data['display_phone_number'] ?? null,
                'verified_name'          => $data['verified_name'] ?? null,
                'quality_rating'         => $data['quality_rating'] ?? null,
                'account_status'         => $data['account_mode'] ?? null,
                'messaging_limit_tier'   => $data['messaging_limit_tier'] ?? null,
                'is_official'            => $data['is_official_business_account'] ?? false,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'connected' => false,
                'error'     => 'Could not reach Meta API: ' . $e->getMessage(),
            ]);
        }
    }
    // ── Send test message ─────────────────────────────────────────────────
    // POST /api/v1/settings/test-send
    public function testSend(Request $request): JsonResponse
    {
        $d = $request->validate([
            'phone'   => ['required', 'string', 'regex:/^[0-9]{10,15}$/'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = auth()->user()->company;

        if (!$company->wa_phone_id || !$company->decrypt_wa_access_token) {
            return response()->json(['message' => 'WhatsApp credentials not set.'], 422);
        }

        $message = $d['message'] ?? 'Hello! This is a test message from WA SaaS Platform. ✅ Your connection is working.';

        try {
            $response = Http::withToken($company->decrypt_wa_access_token)
                ->post("https://graph.facebook.com/v25.0/{$company->wa_phone_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => $d['phone'],
                    'type'              => 'text',
                    'text'              => ['body' => $message, 'preview_url' => false],
                ]);

            if ($response->failed()) {
                $err  = $response->json('error.message') ?? 'Failed to send message.';
                $code = $response->json('error.code');

                // Most common errors explained clearly
                $hint = match ($code) {
                    131030 => "Number {$d['phone']} is not in your Meta test whitelist. During App Review, only whitelisted numbers can receive messages. Go to Meta Developer Console → WhatsApp → API Setup → 'To' section → Add this number.",
                    131047 => 'This number has not opted in to receive messages from your business.',
                    130472 => 'Message failed to send. The recipient WhatsApp account may not exist.',
                    100    => 'Invalid phone number format. Use full international format without + (e.g. 918086544821).',
                    190    => 'Access token expired. Regenerate a permanent system user token.',
                    default => null,
                };

                return response()->json([
                    'message'    => $err . ($hint ? " → {$hint}" : ''),
                    'error_code' => $code,
                    'meta_error' => $response->json('error'),
                ], 422);
            }

            $data = $response->json();

            return response()->json([
                'message'    => 'Test message sent successfully.',
                'wa_msg_id'  => $data['messages'][0]['id'] ?? null,
                'phone'      => $d['phone'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // ── Webhook logs ──────────────────────────────────────────────────────
    public function webhookLogs(): JsonResponse
    {
        $logs = WebhookLog::where('company_id', auth()->user()->company_id)
            ->latest()
            ->limit(50)
            ->get();

        $logs = $logs->map(function ($log) {

            $change = $log->payload['entry'][0]['changes'][0] ?? [];

            $field = $change['field'] ?? 'unknown';

            $messageType = $change['value']['messages'][0]['type'] ?? null;

            return [
                'id' => $log->id,
                'event_type' => $messageType
                    ? "{$field} ({$messageType})"
                    : $field,
                'status' => $log->status,
                'error' => $log->error,
                'created_at' => $log->created_at,
            ];
        });

        return response()->json([
            'logs' => $logs
        ]);
    }
}
