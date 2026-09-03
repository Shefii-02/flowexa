<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WaPhoneNumber;
use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Models\WaOtpCode;
use App\Modules\WaChat\Models\WaOtpLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WaOtpPublicController extends Controller
{
    // No auth middleware — validates Bearer token against wa_otp_services.api_token
    private function resolveService(Request $request): ?WaOtpService
    {
        $token = $request->bearerToken();
        if (!$token) return null;
        return WaOtpService::where('api_token', $token)->where('is_active', true)->first();
    }

    private function checkOrigin(Request $request, WaOtpService $service): bool
    {
        $domains = $service->allowed_domains ?? [];
        if (empty($domains)) return true;
        $origin = $request->header('Origin', '');
        foreach ($domains as $d) {
            if (str_ends_with($origin, trim($d, '/'))) return true;
        }
        return false;
    }

    private function checkPackage(Request $request, WaOtpService $service): bool
    {
        $packages = $service->allowed_packages ?? [];
        if (empty($packages)) return true;
        $pkg = $request->header('X-App-Package', '');
        return in_array($pkg, $packages);
    }

    private const GRAPH_VERSION = 'v20.0';

    private function dispatchText(WaOtpService $service, string $phone, string $message): bool
    {
        $channel = $service->delivery_channel ?? 'waha';
        $digits  = preg_replace('/[^0-9]/', '', $phone);

        try {
            if ($channel === 'meta') {
                $pn = WaPhoneNumber::where('company_id', $service->company_id)
                    ->when($service->wa_phone_number_id, fn($q) => $q->where('id', $service->wa_phone_number_id))
                    ->where('is_active', true)->orderBy('is_default', 'desc')->first();
                if (!$pn) return false;

                return Http::withToken(decrypt($pn->access_token))->timeout(10)
                    ->post("https://graph.facebook.com/" . self::GRAPH_VERSION . "/{$pn->phone_number_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to'   => $digits,
                        'type' => 'text',
                        'text' => ['body' => $message, 'preview_url' => false],
                    ])->successful();
            }

            $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
            $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));

            return Http::withHeaders(['X-API-Key' => $wahaKey])->timeout(10)
                ->post("{$wahaBase}/api/sendText", [
                    'session' => $service->session_id ?? 'default',
                    'chatId'  => $digits . '@c.us',
                    'text'    => $message,
                ])->successful();
        } catch (\Exception) {
            return false;
        }
    }

    private function dispatchFile(WaOtpService $service, string $phone, string $fileUrl, string $filename, string $caption): bool
    {
        $channel = $service->delivery_channel ?? 'waha';
        $digits  = preg_replace('/[^0-9]/', '', $phone);

        try {
            if ($channel === 'meta') {
                $pn = WaPhoneNumber::where('company_id', $service->company_id)
                    ->when($service->wa_phone_number_id, fn($q) => $q->where('id', $service->wa_phone_number_id))
                    ->where('is_active', true)->orderBy('is_default', 'desc')->first();
                if (!$pn) return false;

                return Http::withToken(decrypt($pn->access_token))->timeout(30)
                    ->post("https://graph.facebook.com/" . self::GRAPH_VERSION . "/{$pn->phone_number_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to'       => $digits,
                        'type'     => 'document',
                        'document' => ['link' => $fileUrl, 'filename' => $filename, 'caption' => $caption],
                    ])->successful();
            }

            $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
            $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));

            return Http::withHeaders(['X-API-Key' => $wahaKey])->timeout(30)
                ->post("{$wahaBase}/api/sendFile", [
                    'session' => $service->session_id ?? 'default',
                    'chatId'  => $digits . '@c.us',
                    'file'    => ['url' => $fileUrl, 'filename' => $filename],
                    'caption' => $caption,
                ])->successful();
        } catch (\Exception) {
            return false;
        }
    }

    private function logAction(WaOtpService $service, string $phone, string $action, Request $request, int $responseMs): void
    {
        WaOtpLog::create([
            'company_id'  => $service->company_id,
            'service_id'  => $service->id,
            'phone'       => $phone,
            'action'      => $action,
            'ip_address'  => $request->ip(),
            'domain'      => $request->header('Origin', ''),
            'response_ms' => $responseMs,
        ]);
    }

    public function publicSend(Request $request): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if (!$this->checkOrigin($request, $service)) return response()->json(['error' => 'Origin not allowed.'], 403);
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);

        $data = $request->validate(['phone' => 'required|string|max:20', 'reference_id' => 'nullable|string|max:100']);
        $phone = $data['phone'];

        $otp     = str_pad((string)random_int(0, (int)str_repeat('9', $service->otp_length ?? 6)), $service->otp_length ?? 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes($service->otp_expiry_minutes ?? 10);

        // Expire any previous pending codes for this phone+service
        WaOtpCode::where('service_id', $service->id)->where('phone', $phone)->where('status', 'pending')
            ->update(['status' => 'expired']);

        $code = WaOtpCode::create([
            'company_id'   => $service->company_id,
            'service_id'   => $service->id,
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

        $template = $service->otp_message_template ?? 'Your OTP code is: {{otp}}. Valid for {{expiry}} minutes.';
        $message  = str_replace(['{{otp}}', '{{expiry}}'], [$otp, $service->otp_expiry_minutes ?? 10], $template);

        $sent = $this->dispatchText($service, $phone, $message);
        if (!$sent) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, (int)((microtime(true) - $start) * 1000));
            return response()->json(['error' => 'Failed to send OTP. Check delivery channel settings.'], 500);
        }

        $this->logAction($service, $phone, 'sent', $request, (int)((microtime(true) - $start) * 1000));
        return response()->json(['success' => true, 'expires_at' => $expires->toISOString(), 'reference_id' => $code->reference_id]);
    }

    public function publicVerify(Request $request): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if (!$this->checkOrigin($request, $service)) return response()->json(['error' => 'Origin not allowed.'], 403);
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);

        $data = $request->validate(['phone' => 'required|string|max:20', 'otp' => 'required|string|max:8']);

        $code = WaOtpCode::where('service_id', $service->id)
            ->where('phone', $data['phone'])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if (!$code) {
            return response()->json(['error' => 'OTP expired or not found.'], 422);
        }

        $code->increment('attempts');
        if ($code->otp_code !== $data['otp']) {
            $this->logAction($service, $data['phone'], 'failed', $request, (int)((microtime(true) - $start) * 1000));
            return response()->json(['error' => 'Incorrect OTP.'], 422);
        }

        $code->update(['status' => 'verified', 'verified_at' => now()]);
        $this->logAction($service, $data['phone'], 'verified', $request, (int)((microtime(true) - $start) * 1000));
        return response()->json(['success' => true, 'verified_at' => now()->toISOString()]);
    }

    public function publicResend(Request $request): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if (!$this->checkOrigin($request, $service)) return response()->json(['error' => 'Origin not allowed.'], 403);
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);

        $data = $request->validate(['phone' => 'required|string|max:20']);
        $phone = $data['phone'];

        // Expire old codes
        WaOtpCode::where('service_id', $service->id)->where('phone', $phone)->where('status', 'pending')
            ->update(['status' => 'expired']);

        $otp     = str_pad((string)random_int(0, (int)str_repeat('9', $service->otp_length ?? 6)), $service->otp_length ?? 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes($service->otp_expiry_minutes ?? 10);

        $code = WaOtpCode::create([
            'company_id' => $service->company_id,
            'service_id' => $service->id,
            'phone'      => $phone,
            'otp_code'   => $otp,
            'ip_address' => $request->ip(),
            'domain'     => $request->header('Origin', ''),
            'status'     => 'pending',
            'attempts'   => 0,
            'sent_at'    => now(),
            'expires_at' => $expires,
        ]);

        $template = $service->otp_message_template ?? 'Your OTP code is: {{otp}}. Valid for {{expiry}} minutes.';
        $message  = str_replace(['{{otp}}', '{{expiry}}'], [$otp, $service->otp_expiry_minutes ?? 10], $template);

        $sent = $this->dispatchText($service, $phone, $message);
        if (!$sent) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, (int)((microtime(true) - $start) * 1000));
            return response()->json(['error' => 'Failed to resend OTP. Check delivery channel settings.'], 500);
        }

        $this->logAction($service, $phone, 'resend', $request, (int)((microtime(true) - $start) * 1000));
        return response()->json(['success' => true, 'expires_at' => $expires->toISOString()]);
    }

    // ── Public Utility Message Send ────────────────────────────────────────────
    public function publicUtilitySend(Request $request): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if (!$this->checkOrigin($request, $service)) return response()->json(['error' => 'Origin not allowed.'], 403);
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);

        $data = $request->validate([
            'phone'   => 'required|string|max:20',
            'message' => 'required|string|max:2000',
        ]);

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        $sent  = $this->dispatchText($service, $phone, $data['message']);
        $ms    = (int)((microtime(true) - $start) * 1000);
        $this->logAction($service, $phone, 'utility', $request, $ms);

        return $sent
            ? response()->json(['success' => true, 'phone' => $phone, 'ms' => $ms])
            : response()->json(['error' => 'Delivery failed. Check channel settings.'], 422);
    }

    // ── Public Invoice Share ───────────────────────────────────────────────────
    public function publicInvoiceShare(Request $request): JsonResponse
    {
        $start = microtime(true);
        $service = $this->resolveService($request);
        if (!$service) return response()->json(['error' => 'Invalid or missing API token.'], 401);
        if (!$this->checkOrigin($request, $service)) return response()->json(['error' => 'Origin not allowed.'], 403);
        if (!$this->checkPackage($request, $service)) return response()->json(['error' => 'App package not allowed.'], 403);

        $data = $request->validate([
            'phone'    => 'required|string|max:20',
            'file_url' => 'required|string',
            'filename' => 'nullable|string|max:200',
            'caption'  => 'nullable|string|max:500',
        ]);

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        $sent  = $this->dispatchFile($service, $phone, $data['file_url'], $data['filename'] ?? 'document.pdf', $data['caption'] ?? '');
        $ms    = (int)((microtime(true) - $start) * 1000);
        $this->logAction($service, $phone, 'invoice_share', $request, $ms);

        return $sent
            ? response()->json(['success' => true, 'phone' => $phone, 'ms' => $ms])
            : response()->json(['error' => 'Delivery failed. Check channel settings.'], 422);
    }
}
