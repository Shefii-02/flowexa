<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
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

    private function sendOtpViaWaha(string $phone, string $otp, WaOtpService $service): bool
    {
        $template = $service->otp_message_template ?? 'Your OTP code is: {{otp}}. Valid for {{expiry}} minutes.';
        $message  = str_replace(['{{otp}}', '{{expiry}}'], [$otp, $service->otp_expiry_minutes ?? 10], $template);

        $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
        $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));
        $session  = $service->session_id ?? 'default';

        try {
            $res = Http::withHeaders(['X-API-Key' => $wahaKey])
                ->post("{$wahaBase}/api/sendText", [
                    'session'   => $session,
                    'chatId'    => $phone . '@c.us',
                    'text'      => $message,
                ]);
            return $res->successful();
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

        $sent = $this->sendOtpViaWaha($phone, $otp, $service);
        if (!$sent) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, (int)((microtime(true) - $start) * 1000));
            return response()->json(['error' => 'Failed to send OTP via WhatsApp.'], 500);
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

        $sent = $this->sendOtpViaWaha($phone, $otp, $service);
        if (!$sent) {
            $code->update(['status' => 'failed']);
            $this->logAction($service, $phone, 'failed', $request, (int)((microtime(true) - $start) * 1000));
            return response()->json(['error' => 'Failed to resend OTP via WhatsApp.'], 500);
        }

        $this->logAction($service, $phone, 'resend', $request, (int)((microtime(true) - $start) * 1000));
        return response()->json(['success' => true, 'expires_at' => $expires->toISOString()]);
    }
}
