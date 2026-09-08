<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaCloud\Models\WaCloudApiConfig;
use App\Modules\WaCloud\Models\WaCloudOtpCode;
use App\Modules\WaCloud\Models\WaCloudOtpLog;
use App\Modules\WaCloud\Models\WaCloudOtpService;
use App\Modules\WaCloud\Services\WaCloudMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public WA Cloud Api Service — no auth middleware. The caller is identified
 * purely by `Authorization: Bearer <api_token>` matched against
 * `wa_cloud_otp_services.api_token` (globally unique). Every send goes out as
 * an approved Meta template message via the WhatsApp Cloud API.
 */
class WaCloudApiPublicController extends Controller
{
    public function __construct(private readonly WaCloudMessageService $messages) {}

    private function resolveService(Request $request): ?WaCloudOtpService
    {
        $bearer = $request->bearerToken();
        if (!$bearer) {
            return null;
        }

        return WaCloudOtpService::where('api_token', $bearer)->where('is_active', true)->first();
    }

    /**
     * The named config the caller asked for (`service` field), or the first
     * active one of that kind — but only when its template is approved on Meta.
     */
    private function resolveConfig(Request $request, WaCloudOtpService $service, string $kind): ?WaCloudApiConfig
    {
        $query = WaCloudApiConfig::where('company_id', $service->company_id)
            ->where('kind', $kind)
            ->where('is_active', true);

        $name = $request->input('service');
        if (is_string($name) && $name !== '') {
            $query->where('name', $name);
        } else {
            $query->orderBy('sort_order')->orderBy('id');
        }

        return $query->first();
    }

    private function checkOrigin(Request $request, WaCloudOtpService $service): bool
    {
        $domains = $service->allowed_domains ?? [];
        if (empty($domains)) {
            return true;
        }
        $origin = $request->header('Origin', '');
        foreach ($domains as $d) {
            if ($origin !== '' && str_ends_with($origin, trim($d, '/'))) {
                return true;
            }
        }
        return false;
    }

    private function checkPackage(Request $request, WaCloudOtpService $service): bool
    {
        $packages = $service->allowed_packages ?? [];
        if (empty($packages)) {
            return true;
        }
        return in_array($request->header('X-App-Package', ''), $packages, true);
    }

    /** @return array<string, mixed> */
    private function requestVariables(Request $request): array
    {
        $vars = $request->input('variables', []);
        if (!is_array($vars)) {
            return [];
        }
        $out = [];
        foreach ($vars as $k => $v) {
            if (is_scalar($v)) {
                $out[strtolower(trim((string) $k))] = (string) $v;
            }
        }
        return $out;
    }

    private function logAction(
        WaCloudOtpService $service,
        string $phone,
        string $action,
        Request $request,
        int $responseMs,
        ?WaCloudApiConfig $config = null,
        ?string $waMessageId = null,
        ?string $error = null,
    ): void {
        WaCloudOtpLog::create([
            'company_id'    => $service->company_id,
            'service_id'    => $service->id,
            'config_id'     => $config?->id,
            'phone'         => $phone,
            'action'        => $action,
            'wa_message_id' => $waMessageId,
            'error'         => $error,
            'ip_address'    => $request->ip(),
            'domain'        => $request->header('Origin', ''),
            'response_ms'   => $responseMs,
        ]);
    }

    /** @return JsonResponse|array{0: WaCloudOtpService, 1: ?JsonResponse} */
    private function gate(Request $request): array
    {
        $service = $this->resolveService($request);
        if (!$service) {
            return [null, response()->json(['error' => 'Invalid or missing API token.'], 401)];
        }
        if (!$this->checkOrigin($request, $service)) {
            return [$service, response()->json(['error' => 'Origin not allowed.'], 403)];
        }
        if (!$this->checkPackage($request, $service)) {
            return [$service, response()->json(['error' => 'App package not allowed.'], 403)];
        }
        return [$service, null];
    }

    private function notReady(?WaCloudApiConfig $config): JsonResponse
    {
        $status = $config?->template_status ?? 'not configured';
        return response()->json(['error' => "This service is not ready yet (template {$status})."], 422);
    }

    // ── OTP ──────────────────────────────────────────────────────────────────

    public function publicSend(Request $request): JsonResponse
    {
        return $this->issueOtp($request, 'sent');
    }

    public function publicResend(Request $request): JsonResponse
    {
        return $this->issueOtp($request, 'resend');
    }

    private function issueOtp(Request $request, string $action): JsonResponse
    {
        [$service, $fail] = $this->gate($request);
        if ($fail) {
            return $fail;
        }

        $data = $request->validate([
            'phone'        => 'required|string|max:20',
            'reference_id' => 'nullable|string|max:100',
            'service'      => 'nullable|string|max:100',
            'variables'    => 'nullable|array',
        ]);
        $phone = $data['phone'];

        $config = $this->resolveConfig($request, $service, 'auth');
        if (!$config || !$config->isSendable()) {
            return $this->notReady($config);
        }

        $length  = $config->otp_length ?? 6;
        $expiryM = $config->otp_expiry_minutes ?? 10;
        $otp     = str_pad((string) random_int(0, (int) str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes($expiryM);

        WaCloudOtpCode::where('service_id', $service->id)->where('phone', $phone)->where('status', 'pending')
            ->update(['status' => 'expired']);

        $code = WaCloudOtpCode::create([
            'company_id'   => $service->company_id,
            'service_id'   => $service->id,
            'config_id'    => $config->id,
            'phone'        => $phone,
            'otp_code'     => $otp,
            'reference_id' => $data['reference_id'] ?? null,
            'ip_address'   => $request->ip(),
            'domain'       => $request->header('Origin', ''),
            'status'       => 'pending',
            'attempts'     => 0,
            'sent_at'      => now(),
            'expires_at'   => $expires,
        ]);

        [$ok, $waMessageId, $error, $ms] = $this->messages->sendAuth($service->company, $config, $phone, $otp);

        if (!$ok) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, $ms, $config, null, $error);
            return response()->json(['error' => 'Sending is temporarily unavailable. Contact the account owner.'], 500);
        }

        $this->logAction($service, $phone, $action, $request, $ms, $config, $waMessageId);
        return response()->json([
            'success'      => true,
            'expires_at'   => $expires->toISOString(),
            'reference_id' => $code->reference_id,
        ]);
    }

    public function publicVerify(Request $request): JsonResponse
    {
        $start = microtime(true);
        [$service, $fail] = $this->gate($request);
        if ($fail) {
            return $fail;
        }

        $data = $request->validate([
            'phone'   => 'required|string|max:20',
            'otp'     => 'required|string|max:10',
            'service' => 'nullable|string|max:100',
        ]);

        $code = WaCloudOtpCode::where('service_id', $service->id)
            ->where('phone', $data['phone'])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$code) {
            return response()->json(['error' => 'OTP expired or not found.'], 422);
        }

        $config      = $this->resolveConfig($request, $service, 'auth');
        $maxAttempts = $config?->max_attempts ?? 5;
        $ms          = (int) ((microtime(true) - $start) * 1000);

        $code->increment('attempts');

        if ($code->otp_code !== $data['otp']) {
            if ($code->attempts >= $maxAttempts) {
                $code->update(['status' => 'failed']);
                $this->logAction($service, $data['phone'], 'failed', $request, $ms, $config);
                return response()->json(['error' => 'Too many incorrect attempts. Request a new OTP.'], 429);
            }
            $this->logAction($service, $data['phone'], 'failed', $request, $ms, $config);
            return response()->json([
                'error'         => 'Incorrect OTP.',
                'attempts_left' => max(0, $maxAttempts - $code->attempts),
            ], 422);
        }

        $code->update(['status' => 'verified', 'verified_at' => now()]);
        $this->logAction($service, $data['phone'], 'verified', $request, $ms, $config);
        return response()->json(['success' => true, 'verified_at' => now()->toISOString()]);
    }

    // ── Utility ──────────────────────────────────────────────────────────────

    public function publicUtilitySend(Request $request): JsonResponse
    {
        [$service, $fail] = $this->gate($request);
        if ($fail) {
            return $fail;
        }

        $data = $request->validate([
            'phone'     => 'required|string|max:20',
            'service'   => 'nullable|string|max:100',
            'variables' => 'nullable|array',
        ]);

        $config = $this->resolveConfig($request, $service, 'utility');
        if (!$config || !$config->isSendable()) {
            return $this->notReady($config);
        }

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        [$ok, $waMessageId, $error, $ms] = $this->messages->sendUtility(
            $service->company,
            $config,
            $phone,
            $this->requestVariables($request),
        );
        $this->logAction($service, $phone, 'utility', $request, $ms, $config, $waMessageId, $ok ? null : $error);

        return $ok
            ? response()->json(['success' => true, 'phone' => $phone, 'wa_message_id' => $waMessageId, 'ms' => $ms])
            : response()->json(['error' => 'Sending is temporarily unavailable. Contact the account owner.'], 500);
    }

    // ── Invoice / document ───────────────────────────────────────────────────

    public function publicInvoiceShare(Request $request): JsonResponse
    {
        [$service, $fail] = $this->gate($request);
        if ($fail) {
            return $fail;
        }

        $data = $request->validate([
            'phone'        => 'required|string|max:20',
            'service'      => 'nullable|string|max:100',
            'document_url' => 'required|url',
            'filename'     => 'nullable|string|max:200',
            'variables'    => 'nullable|array',
        ]);

        $config = $this->resolveConfig($request, $service, 'invoice');
        if (!$config || !$config->isSendable()) {
            return $this->notReady($config);
        }

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        [$ok, $waMessageId, $error, $ms] = $this->messages->sendInvoice(
            $service->company,
            $config,
            $phone,
            $data['document_url'],
            $data['filename'] ?? null,
            $this->requestVariables($request),
        );
        $this->logAction($service, $phone, 'invoice_share', $request, $ms, $config, $waMessageId, $ok ? null : $error);

        return $ok
            ? response()->json(['success' => true, 'phone' => $phone, 'wa_message_id' => $waMessageId, 'ms' => $ms])
            : response()->json(['error' => 'Sending is temporarily unavailable. Contact the account owner.'], 500);
    }
}
