<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\WaChat\Models\WaApiConfig;
use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Models\WaOtpCode;
use App\Modules\WaChat\Models\WaOtpLog;
use App\Modules\WaChat\Services\OpenWaMessageService;
use App\Modules\WaChat\Support\ApiAccess;
use App\Modules\WaChat\Support\ApiTemplateVariables;
use App\Modules\WaChat\Support\OtpDeliveryFailure;
use App\Modules\WaChat\Support\OtpSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WaOtpPublicController extends Controller
{
    // No auth middleware. The {waChatToken} path segment identifies the company
    // (Company.wa_chat_token); the Authorization: Bearer <api_token> header then
    // authenticates that company's Api Service (wa_otp_services.api_token).
    private function resolveService(Request $request, string $waChatToken): ?WaOtpService
    {
        $company = Company::where('wa_chat_token', $waChatToken)->first();
        if (!$company) return null;

        $bearer = $request->bearerToken();
        if (!$bearer) return null;

        return WaOtpService::where('company_id', $company->id)
            ->where('api_token', $bearer)
            ->where('is_active', true)
            ->first();
    }

    /**
     * The named `wa_api_configs` row the caller asked for via the `service`
     * field, or the first active config of that kind. May be null — callers
     * fall back to the legacy `wa_otp_services` columns / built-in defaults.
     */
    private function resolveConfig(Request $request, WaOtpService $service, string $kind): ?WaApiConfig
    {
        $query = WaApiConfig::where('company_id', $service->company_id)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->with('prebuiltTemplate:id,name,type,language,content');

        $templateId = $request->input('template_id');
        $name = $request->input('service');
        if ($templateId !== null && $templateId !== '') {
            $query->whereKey((int) $templateId);                       // template_id = the id shown on the config card — exact, never a silent fallback
        } elseif (is_string($name) && $name !== '') {
            $query->where('name', $name);
        } else {
            $query->orderBy('sort_order')->orderBy('id');
        }

        return $query->first();
    }

    /** Replace {{key}} / {{ key }} tokens from a flat map, leaving unknown tokens intact. */
    private function fillPlaceholders(string $template, array $map): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function ($m) use ($map) {
            $key = strtolower(trim($m[1]));
            return array_key_exists($key, $map) ? (string) $map[$key] : $m[0];
        }, $template);
    }

    /** Company-name / date tokens shared by utility + OTP message rendering. */
    private function companyTokens(WaOtpService $service): array
    {
        $name = optional($service->company)->name ?? 'Us';
        return [
            'company'       => $name,
            'company_name'  => $name,
            'business name' => $name,
            'time'          => now()->format('h:i A'),
            'date'          => now()->format('d M Y'),
        ];
    }

    /**
     * Caller-supplied template variables, keyed by name. Every {{key}} in the
     * body is replaced by `variables[key]` (case-insensitive); the auto tokens
     * (otp/code/expiry, company name) still win.
     */
    private function requestVariables(Request $request): array
    {
        $vars = $request->input('variables', []);
        if (!is_array($vars)) return [];

        $out = [];
        foreach ($vars as $k => $v) {
            if (is_scalar($v)) {
                $out[strtolower(trim((string) $k))] = (string) $v;
            }
        }
        return $out;
    }

    /** `template_id` was sent but is not an active config of this kind in this company → the 404 to return. */
    private function unknownTemplate(Request $request, ?WaApiConfig $config): ?JsonResponse
    {
        $tid = $request->input('template_id');
        if ($tid === null || $tid === '' || $config) { return null; }
        return response()->json(['error' => "Template {$tid} was not found for this API (use the id shown on the template's card).", 'code' => 'template_not_found'], 404);
    }

    /** The caller's `variables` must cover every {{token}} the template needs, each with a usable value. Null = they match. */
    private function variablesMismatch(string $template, Request $request, ?WaApiConfig $config): ?JsonResponse
    {
        $check = ApiTemplateVariables::check($template, (array) $request->input('variables', []));
        if ($check['ok']) { return null; }
        return response()->json(ApiTemplateVariables::failure($check, $config?->id, $config?->prebuiltTemplate?->name ?? $config?->name), 422);
    }

    /** Extra response fields: which template was used, and any variables the template does not use (ignored, reported so a typo is easy to spot). */
    private function templateInfo(Request $request, ?WaApiConfig $config, string $template): array
    {
        $ignored = ApiTemplateVariables::check($template, (array) $request->input('variables', []))['ignored'];
        return ($config ? ['template_id' => $config->id] : []) + ($ignored ? ['ignored_variables' => $ignored] : []);
    }

    /** OTP body text before the values are filled in: config template → legacy column → hard default. */
    private function otpTemplateText(WaOtpService $service, ?WaApiConfig $config): string
    {
        return $config?->prebuiltTemplate?->content
            ?? $service->otp_message_template
            ?? 'Your OTP code is: {{otp}}. Valid for {{expiry}} minutes.';
    }

    /** OTP body for a send/resend: config template → legacy column → hard default. */
    private function otpMessage(WaOtpService $service, ?WaApiConfig $config, string $otp, int $expiry, array $variables = []): string
    {
        $template = $this->otpTemplateText($service, $config);

        return $this->fillPlaceholders($template, array_merge($variables, $this->companyTokens($service), [
            'otp'    => $otp,
            'code'   => $otp,
            'expiry' => $expiry,
        ]));
    }

    /**
     * Who may call, and how often (see ApiAccess): signature (if required) → allowed IPs → website / curl rules → the per-minute limit.
     * Null = the call may go ahead.
     */
    private function accessDenied(Request $request, WaOtpService $service): ?JsonResponse
    {
        if ($d = ApiAccess::check($request, $service)) {
            return response()->json(['error' => $d[2], 'code' => $d[1]], $d[0]);
        }
        if ($t = ApiAccess::throttle($service)) {
            return response()->json(['error' => $t[2], 'code' => $t[1], 'retry_after' => $t[3]], $t[0])->header('Retry-After', (string) $t[3]);
        }
        return null;
    }

    /** Messages to one number per hour — checked only once a request is valid and about to send. */
    private function phoneLimited(WaOtpService $service, string $phone): ?JsonResponse
    {
        $t = ApiAccess::throttlePhone($service, $phone);
        return $t ? response()->json(['error' => $t[2], 'code' => $t[1], 'retry_after' => $t[3]], $t[0])->header('Retry-After', (string) $t[3]) : null;
    }

    private function checkPackage(Request $request, WaOtpService $service): bool
    {
        $packages = $service->allowed_packages ?? [];
        if (empty($packages)) return true;
        $pkg = $request->header('X-App-Package', '');
        return in_array($pkg, $packages);
    }

    /** Optional `session_id` in the request body: send through that WhatsApp session instead of the one saved on the API service. */
    private function requestedSession(Request $request): ?string
    {
        $request->validate(['session_id' => OtpSession::RULE]);
        return $request->input('session_id') ?: null;
    }

    /** Per-company X-API-Key for the open-wa gateway. */
    private function apiKey(WaOtpService $service): string
    {
        return (string) (optional($service->company)->wa_chat_token ?? '');
    }

    /** Why the last dispatchText/dispatchFile call failed (set on failure, shown to the API caller). */
    private ?string $deliveryFailure = null;

    private function dispatchText(WaOtpService $service, string $phone, string $message, ?string $sessionId = null): bool
    {
        return $this->deliver($service, $sessionId, fn (string $sid) => app(OpenWaMessageService::class)->billedAs('api', (int) $service->company_id)
            ->sendText($sid, $this->apiKey($service), $this->chatId($phone), $message));
    }

    private function dispatchFile(WaOtpService $service, string $phone, string $fileUrl, string $filename, string $caption, ?string $sessionId = null): bool
    {
        return $this->deliver($service, $sessionId, fn (string $sid) => app(OpenWaMessageService::class)->billedAs('api', (int) $service->company_id)
            ->sendMedia($sid, $this->apiKey($service), $this->chatId($phone), 'document', array_filter([
                'url'      => $fileUrl,
                'filename' => $filename,
                'caption'  => $caption ?: null,
            ], fn ($v) => $v !== null)));
    }

    private function chatId(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) . '@c.us';
    }

    /**
     * Hand a message to the gateway and record, rather than swallow, why it failed. A missing session used to fall back to the
     * literal id "default", which the gateway has never had, so such a service could never deliver and nothing said why.
     */
    private function deliver(WaOtpService $service, ?string $sessionId, \Closure $send): bool
    {
        $this->deliveryFailure = null;
        $sid = $sessionId ?: $service->session_id;
        if (!$sid) {
            $this->deliveryFailure = OtpDeliveryFailure::NO_SESSION;
            \Log::warning('OTP delivery skipped: no WhatsApp session configured', ['service_id' => $service->id, 'company_id' => $service->company_id]);
            return false;
        }

        try {
            $response = $send($sid);
            if ($response->successful()) { return true; }
            $this->deliveryFailure = OtpDeliveryFailure::fromStatus($response->status(), $response->json('message'));
            \Log::warning('OTP delivery refused by gateway', ['service_id' => $service->id, 'session_id' => $sid, 'status' => $response->status()]);
        } catch (\Throwable $e) {
            $this->deliveryFailure = OtpDeliveryFailure::UNREACHABLE;
            \Log::error('OTP delivery failed: ' . $e->getMessage(), ['service_id' => $service->id, 'session_id' => $sid]);
        }
        return false;
    }

    private function logAction(WaOtpService $service, string $phone, string $action, Request $request, int $responseMs, ?WaApiConfig $config = null): void
    {
        WaOtpLog::create([
            'company_id'  => $service->company_id,
            'service_id'  => $service->id,
            'config_id'   => $config?->id,
            'session_id'  => $config?->session_id ?: ($service->session_id ?: null),
            'phone'       => $phone,
            'action'      => $action,
            'ip_address'  => ApiAccess::clientIp($request),
            'domain'      => $request->header('Origin', ''),
            'response_ms' => $responseMs,
        ]);
    }

    public function publicSend(Request $request, string $waChatToken): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request, $waChatToken);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if ($denied = $this->accessDenied($request, $service)) return $denied;
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);
        if ($why = $this->billingRefusal($service)) return response()->json(['error' => $why, 'code' => 'insufficient_balance'], 402);   // each delivered message is paid from the wallet

        $data = $request->validate([
            'phone'        => 'required|string|max:20',
            'reference_id' => 'nullable|string|max:100',
            'service'      => 'nullable|string|max:100',
            'template_id'  => 'nullable|integer',
            'variables'    => 'nullable|array',
        ]);
        $phone = $data['phone'];

        $config  = $this->resolveConfig($request, $service, 'auth');
        if ($r = $this->unknownTemplate($request, $config)) return $r;
        if ($r = $this->variablesMismatch($this->otpTemplateText($service, $config), $request, $config)) return $r;   // before anything is created or expired
        if ($r = $this->phoneLimited($service, $phone)) return $r;
        $length  = $config?->otp_length ?? $service->otp_length ?? 6;
        $expiryM = $config?->otp_expiry_minutes ?? $service->otp_expiry_minutes ?? 10;

        $otp     = str_pad((string)random_int(0, (int)str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes($expiryM);

        // Expire any previous pending codes for this phone+service
        WaOtpCode::where('service_id', $service->id)->where('phone', $phone)->where('status', 'pending')
            ->update(['status' => 'expired']);

        $code = WaOtpCode::create([
            'company_id'   => $service->company_id,
            'service_id'   => $service->id,
            'config_id'    => $config?->id,
            'phone'        => $phone,
            'otp_code'     => $otp,
            'reference_id' => $data['reference_id'] ?? null,
            'ip_address'   => ApiAccess::clientIp($request),
            'domain'       => $request->header('Origin', ''),
            'status'       => 'pending',
            'attempts'     => 0,
            'sent_at'      => now(),
            'expires_at'   => $expires,
        ]);

        $message = $this->otpMessage($service, $config, $otp, $expiryM, $this->requestVariables($request));

        $sent = $this->dispatchText($service, $phone, $message, OtpSession::pick($this->requestedSession($request), $config?->session_id, null));
        if (!$sent) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, (int)((microtime(true) - $start) * 1000), $config);
            return response()->json(['error' => 'Failed to send OTP. Check delivery channel settings.', 'reason' => $this->deliveryFailure], 500);
        }

        $this->logAction($service, $phone, 'sent', $request, (int)((microtime(true) - $start) * 1000), $config);
        return response()->json(['success' => true, 'expires_at' => $expires->toISOString(), 'reference_id' => $code->reference_id] + $this->templateInfo($request, $config, $this->otpTemplateText($service, $config)));
    }

    public function publicVerify(Request $request, string $waChatToken): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request, $waChatToken);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if ($denied = $this->accessDenied($request, $service)) return $denied;
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);

        $data = $request->validate([
            'phone'       => 'required|string|max:20',
            'otp'         => 'required|string|max:10',
            'service'     => 'nullable|string|max:100',
            'template_id' => 'nullable|integer',
        ]);

        $code = WaOtpCode::where('service_id', $service->id)
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

        $code->increment('attempts');

        if ($code->otp_code !== $data['otp']) {
            if ($code->attempts >= $maxAttempts) {
                $code->update(['status' => 'failed']);
                $this->logAction($service, $data['phone'], 'failed', $request, (int)((microtime(true) - $start) * 1000), $config);
                return response()->json(['error' => 'Too many incorrect attempts. Request a new OTP.'], 429);
            }
            $this->logAction($service, $data['phone'], 'failed', $request, (int)((microtime(true) - $start) * 1000), $config);
            return response()->json(['error' => 'Incorrect OTP.', 'attempts_left' => max(0, $maxAttempts - $code->attempts)], 422);
        }

        $code->update(['status' => 'verified', 'verified_at' => now()]);
        $this->logAction($service, $data['phone'], 'verified', $request, (int)((microtime(true) - $start) * 1000), $config);
        return response()->json(['success' => true, 'verified_at' => now()->toISOString()]);
    }

    public function publicResend(Request $request, string $waChatToken): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request, $waChatToken);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if ($denied = $this->accessDenied($request, $service)) return $denied;
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);
        if ($why = $this->billingRefusal($service)) return response()->json(['error' => $why, 'code' => 'insufficient_balance'], 402);   // each delivered message is paid from the wallet

        $data = $request->validate([
            'phone'       => 'required|string|max:20',
            'service'     => 'nullable|string|max:100',
            'template_id' => 'nullable|integer',
            'variables'   => 'nullable|array',
        ]);
        $phone = $data['phone'];

        $config  = $this->resolveConfig($request, $service, 'auth');
        if ($r = $this->unknownTemplate($request, $config)) return $r;
        if ($r = $this->variablesMismatch($this->otpTemplateText($service, $config), $request, $config)) return $r;   // before anything is created or expired
        if ($r = $this->phoneLimited($service, $phone)) return $r;

        // Expire old codes — only now that the request is known to be valid and will actually send a new one
        WaOtpCode::where('service_id', $service->id)->where('phone', $phone)->where('status', 'pending')
            ->update(['status' => 'expired']);

        $length  = $config?->otp_length ?? $service->otp_length ?? 6;
        $expiryM = $config?->otp_expiry_minutes ?? $service->otp_expiry_minutes ?? 10;

        $otp     = str_pad((string)random_int(0, (int)str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes($expiryM);

        $code = WaOtpCode::create([
            'company_id' => $service->company_id,
            'service_id' => $service->id,
            'config_id'  => $config?->id,
            'phone'      => $phone,
            'otp_code'   => $otp,
            'ip_address' => ApiAccess::clientIp($request),
            'domain'     => $request->header('Origin', ''),
            'status'     => 'pending',
            'attempts'   => 0,
            'sent_at'    => now(),
            'expires_at' => $expires,
        ]);

        $message = $this->otpMessage($service, $config, $otp, $expiryM, $this->requestVariables($request));

        $sent = $this->dispatchText($service, $phone, $message, OtpSession::pick($this->requestedSession($request), $config?->session_id, null));
        if (!$sent) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, (int)((microtime(true) - $start) * 1000), $config);
            return response()->json(['error' => 'Failed to resend OTP. Check delivery channel settings.', 'reason' => $this->deliveryFailure], 500);
        }

        $this->logAction($service, $phone, 'resend', $request, (int)((microtime(true) - $start) * 1000), $config);
        return response()->json(['success' => true, 'expires_at' => $expires->toISOString()] + $this->templateInfo($request, $config, $this->otpTemplateText($service, $config)));
    }

    // ── Public Utility Message Send ────────────────────────────────────────────
    public function publicUtilitySend(Request $request, string $waChatToken): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request, $waChatToken);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if ($denied = $this->accessDenied($request, $service)) return $denied;
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);
        if ($why = $this->billingRefusal($service)) return response()->json(['error' => $why, 'code' => 'insufficient_balance'], 402);   // each delivered message is paid from the wallet

        $data = $request->validate([
            'phone'       => 'required|string|max:20',
            'message'     => 'nullable|string|max:2000',
            'service'     => 'nullable|string|max:100',
            'template_id' => 'nullable|integer',
            'variables'   => 'nullable|array',
        ]);

        $config  = $this->resolveConfig($request, $service, 'utility');
        if ($r = $this->unknownTemplate($request, $config)) return $r;
        $message = $data['message'] ?? $config?->resolveContent();

        if (!$message) {
            return response()->json(['error' => 'No message content — pass "message" or configure a utility service.'], 422);
        }
        if ($r = $this->variablesMismatch($message, $request, $config)) return $r;
        if ($r = $this->phoneLimited($service, $data['phone'])) return $r;
        $rawTemplate = $message;   // the text with its {{tokens}} — what the response compares the caller's variables against

        $message = $this->fillPlaceholders($message, array_merge(
            $this->requestVariables($request),
            $this->companyTokens($service),
        ));

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        $sent  = $this->dispatchText($service, $phone, $message, OtpSession::pick($this->requestedSession($request), $config?->session_id, null));
        $ms    = (int)((microtime(true) - $start) * 1000);
        $this->logAction($service, $phone, 'utility', $request, $ms, $config);

        return $sent
            ? response()->json(['success' => true, 'phone' => $phone, 'ms' => $ms] + $this->templateInfo($request, $config, $rawTemplate))
            : response()->json(['error' => 'Delivery failed. Check channel settings.', 'reason' => $this->deliveryFailure], 422);
    }

    // ── Public Invoice Share ───────────────────────────────────────────────────
    public function publicInvoiceShare(Request $request, string $waChatToken): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request, $waChatToken);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if ($denied = $this->accessDenied($request, $service)) return $denied;
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);
        if ($why = $this->billingRefusal($service)) return response()->json(['error' => $why, 'code' => 'insufficient_balance'], 402);   // each delivered message is paid from the wallet

        $data = $request->validate([
            'phone'    => 'required|string|max:20',
            'file_url' => 'nullable|string',
            'filename' => 'nullable|string|max:200',
            'caption'  => 'nullable|string|max:500',
            'service'  => 'nullable|string|max:100',
            'template_id' => 'nullable|integer',
        ]);

        $config   = $this->resolveConfig($request, $service, 'invoice');
        if ($r = $this->unknownTemplate($request, $config)) return $r;
        if ($r = $this->phoneLimited($service, $data['phone'])) return $r;
        $fileUrl  = $data['file_url'] ?? $config?->file_url;
        $filename = $data['filename'] ?? $config?->filename ?? 'document.pdf';
        $caption  = $data['caption'] ?? $config?->caption ?? '';

        if (!$fileUrl) {
            return response()->json(['error' => 'No file_url — pass one or configure an invoice service.'], 422);
        }

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        $sent  = $this->dispatchFile($service, $phone, $fileUrl, $filename, $caption, OtpSession::pick($this->requestedSession($request), $config?->session_id, null));
        $ms    = (int)((microtime(true) - $start) * 1000);
        $this->logAction($service, $phone, 'invoice_share', $request, $ms, $config);

        return $sent
            ? response()->json(['success' => true, 'phone' => $phone, 'ms' => $ms])
            : response()->json(['error' => 'Delivery failed. Check channel settings.', 'reason' => $this->deliveryFailure], 422);
    }

    private function billingRefusal($service): ?string
    {
        $company = \App\Models\Company::find($service->company_id);
        return $company ? app(\App\Modules\WaChat\Services\WaChatBilling::class)->refusal($company) : null;
    }
}
