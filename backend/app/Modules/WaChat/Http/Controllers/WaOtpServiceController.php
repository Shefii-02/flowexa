<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WaPhoneNumber;
use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Models\WaAuthMessage;
use App\Modules\WaChat\Models\WaOtpLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WaOtpServiceController extends Controller
{
    private const GRAPH_VERSION = 'v20.0';

    /** Send a plain-text message via WA Chat (WAHA) or Cloud Meta API. Returns response time in ms or null on failure. */
    private function dispatchText(WaOtpService $service, string $phone, string $message, ?string $sessionId = null): ?int
    {
        $channel = $service->delivery_channel ?? 'waha';
        $start   = microtime(true);

        if ($channel === 'meta') {
            $phoneNumber = WaPhoneNumber::where('company_id', $service->company_id)
                ->when($service->wa_phone_number_id, fn($q) => $q->where('id', $service->wa_phone_number_id))
                ->where('is_active', true)->orderBy('is_default', 'desc')->first();

            if (!$phoneNumber) return null;

            $res = Http::withToken(decrypt($phoneNumber->access_token))
                ->timeout(10)
                ->post("https://graph.facebook.com/" . self::GRAPH_VERSION . "/{$phoneNumber->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'   => preg_replace('/[^0-9]/', '', $phone),
                    'type' => 'text',
                    'text' => ['body' => $message, 'preview_url' => false],
                ]);
        } else {
            $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
            $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));
            $sid      = $sessionId ?? $service->session_id ?? 'default';

            $res = Http::withHeaders(['X-API-Key' => $wahaKey])->timeout(10)
                ->post("{$wahaBase}/api/sendText", [
                    'session' => $sid,
                    'chatId'  => preg_replace('/[^0-9]/', '', $phone) . '@c.us',
                    'text'    => $message,
                ]);
        }

        return $res->successful() ? (int)((microtime(true) - $start) * 1000) : null;
    }

    /** Send a document/file via WA Chat (WAHA) or Cloud Meta API. Returns ms or null on failure. */
    private function dispatchFile(WaOtpService $service, string $phone, string $fileUrl, string $filename, string $caption, ?string $sessionId = null): ?int
    {
        $channel = $service->delivery_channel ?? 'waha';
        $start   = microtime(true);

        if ($channel === 'meta') {
            $phoneNumber = WaPhoneNumber::where('company_id', $service->company_id)
                ->when($service->wa_phone_number_id, fn($q) => $q->where('id', $service->wa_phone_number_id))
                ->where('is_active', true)->orderBy('is_default', 'desc')->first();

            if (!$phoneNumber) return null;

            $res = Http::withToken(decrypt($phoneNumber->access_token))
                ->timeout(30)
                ->post("https://graph.facebook.com/" . self::GRAPH_VERSION . "/{$phoneNumber->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'       => preg_replace('/[^0-9]/', '', $phone),
                    'type'     => 'document',
                    'document' => ['link' => $fileUrl, 'filename' => $filename, 'caption' => $caption],
                ]);
        } else {
            $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
            $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));
            $sid      = $sessionId ?? $service->session_id ?? 'default';

            $res = Http::withHeaders(['X-API-Key' => $wahaKey])->timeout(30)
                ->post("{$wahaBase}/api/sendFile", [
                    'session' => $sid,
                    'chatId'  => preg_replace('/[^0-9]/', '', $phone) . '@c.us',
                    'file'    => ['url' => $fileUrl, 'filename' => $filename],
                    'caption' => $caption,
                ]);
        }

        return $res->successful() ? (int)((microtime(true) - $start) * 1000) : null;
    }


    public function show(): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->first();
        return response()->json(['data' => $service]);
    }

    public function storeOrUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active'            => 'boolean',
            'allowed_domains'      => 'nullable|array',
            'allowed_packages'     => 'nullable|array',
            'otp_expiry_minutes'   => 'integer|min:1|max:60',
            'otp_length'           => 'integer|min:4|max:8',
            'otp_message_template' => 'nullable|string|max:500',
            'session_id'           => 'nullable|string|max:100',
            'delivery_channel'     => 'nullable|in:waha,meta',
            'wa_phone_number_id'   => 'nullable|integer',
        ]);

        $companyId = auth()->user()->company_id;
        $service = WaOtpService::updateOrCreate(['company_id' => $companyId], $data);
        return response()->json(['message' => 'OTP service saved.', 'data' => $service]);
    }

    public function resetToken(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $service = WaOtpService::firstOrCreate(['company_id' => $companyId]);
        $service->update([
            'api_token'            => Str::random(64),
            'api_token_created_at' => now(),
        ]);
        return response()->json(['message' => 'Token regenerated.', 'data' => $service]);
    }

    public function stopToken(): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->firstOrFail();
        $service->update(['is_active' => false, 'api_token' => null]);
        return response()->json(['message' => 'Token revoked and service deactivated.']);
    }

    public function listAuthMessages(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $messages = WaAuthMessage::where('company_id', $companyId)
            ->orderBy('sort_order')->get();

        // Seed defaults if empty
        if ($messages->isEmpty()) {
            $defaults = WaAuthMessage::defaultTemplates($companyId);
            $rows = [];
            foreach ($defaults as $i => $d) {
                $rows[] = WaAuthMessage::create(array_merge($d, [
                    'company_id' => $companyId,
                    'sort_order' => $i,
                ]));
            }
            $messages = collect($rows);
        }

        return response()->json(['data' => $messages]);
    }

    public function createAuthMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:150',
            'type'             => 'required|in:otp,welcome,password_reset,login_alert,custom',
            'message_template' => 'required|string',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
        ]);
        $msg = WaAuthMessage::create(array_merge($data, ['company_id' => auth()->user()->company_id]));
        return response()->json(['message' => 'Auth message created.', 'data' => $msg], 201);
    }

    public function updateAuthMessage(Request $request, int $id): JsonResponse
    {
        $msg = WaAuthMessage::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'name'             => 'sometimes|string|max:150',
            'message_template' => 'sometimes|string',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
        ]);
        $msg->update($data);
        return response()->json(['message' => 'Auth message updated.', 'data' => $msg]);
    }

    public function testSend(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $service = WaOtpService::where('company_id', auth()->user()->company_id)
            ->first();

        if (!$service) {
            $companyId = auth()->user()->company_id;
            $service = WaOtpService::create(['company_id' => $companyId]);
        }

        $length   = $service->otp_length ?? 6;
        $otp      = str_pad((string) random_int(0, (int) pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        $template = $service->otp_message_template ?? 'Your OTP is {{otp}}. Valid for {{expiry}} minutes.';
        $message  = str_replace(['{{otp}}', '{{expiry}}'], [$otp, $service->otp_expiry_minutes ?? 10], $template);
        $phone    = preg_replace('/[^0-9]/', '', $request->phone);

        try {
            $ms = $this->dispatchText($service, $phone, $message);
            if ($ms !== null) {
                WaOtpLog::create([
                    'company_id'  => $service->company_id,
                    'service_id'  => $service->id,
                    'phone'       => $phone,
                    'action'      => 'sent',
                    'ip_address'  => $request->ip(),
                    'domain'      => 'dashboard-test',
                    'response_ms' => $ms,
                ]);
                return response()->json(['success' => true, 'otp' => $otp, 'phone' => $phone, 'message' => $message, 'ms' => $ms]);
            }
            return response()->json(['success' => false, 'error' => 'Delivery failed. Check channel settings.'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->firstOrFail();
        $logs = WaOtpLog::where('service_id', $service->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);
        return response()->json($logs);
    }

    /** List utility / general-purpose templates (non-OTP types). Seeds defaults on first call. */
    public function listUtilityTemplates(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $company   = auth()->user()->company;

        $templates = WaAuthMessage::where('company_id', $companyId)
            ->whereIn('type', ['utility', 'welcome', 'payment_reminder', 'appointment', 'custom'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($templates->isEmpty()) {
            $defaults = WaAuthMessage::defaultUtilityTemplates($companyId, $company->name ?? 'Us');
            foreach ($defaults as $d) {
                WaAuthMessage::create($d);
            }
            $templates = WaAuthMessage::where('company_id', $companyId)
                ->whereIn('type', ['utility', 'welcome', 'payment_reminder', 'appointment', 'custom'])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        return response()->json(['data' => $templates]);
    }

    /** Send a pre-built utility / transactional message to a phone number. */
    public function utilityMessageSend(Request $request): JsonResponse
    {
        $request->validate([
            'phone'       => 'required|string',
            'template_id' => 'nullable|integer',
            'message'     => 'nullable|string|max:2000',
            'session_id'  => 'nullable|string|max:100',
        ]);

        $service = WaOtpService::where('company_id', auth()->user()->company_id)->first();
        $sessionId = $request->session_id ?? ($service?->session_id ?? 'default');

        $message = $request->message;
        if (!$message && $request->template_id) {
            $template = WaAuthMessage::where('company_id', auth()->user()->company_id)
                ->where('id', $request->template_id)->first();
            if ($template) {
                $company = auth()->user()->company;
                $message = str_replace(
                    ['{{company}}', '{{company_name}}', '{{website/app_name}}', '{{time}}', '{{date}}'],
                    [$company->name ?? 'Us', $company->name ?? 'Us', $company->name ?? 'Us', now()->format('h:i A'), now()->format('d M Y')],
                    $template->message_template
                );
            }
        }

        if (!$message) {
            return response()->json(['success' => false, 'error' => 'No message content.'], 422);
        }

        $phone = preg_replace('/[^0-9]/', '', $request->phone);

        try {
            $ms = $this->dispatchText($service, $phone, $message, $sessionId);
            if ($ms !== null) {
                if ($service) WaOtpLog::create([
                    'company_id'  => $service->company_id,
                    'service_id'  => $service->id,
                    'phone'       => $phone,
                    'action'      => 'utility',
                    'ip_address'  => $request->ip(),
                    'domain'      => 'dashboard-utility',
                    'response_ms' => $ms,
                ]);
                return response()->json(['success' => true, 'phone' => $phone, 'message' => $message, 'ms' => $ms]);
            }
            return response()->json(['success' => false, 'error' => 'Delivery failed. Check channel settings.'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Share an invoice / document file to a customer's WhatsApp number. */
    public function invoiceShare(Request $request): JsonResponse
    {
        $request->validate([
            'phone'      => 'required|string',
            'file_url'   => 'nullable|string',
            'file'       => 'nullable|file|max:20480',
            'caption'    => 'nullable|string|max:500',
            'filename'   => 'nullable|string|max:200',
            'session_id' => 'nullable|string|max:100',
        ]);

        $service = WaOtpService::where('company_id', auth()->user()->company_id)->first();
        $sessionId = $request->session_id ?? ($service?->session_id ?? 'default');

        $phone    = preg_replace('/[^0-9]/', '', $request->phone);
        $fileUrl  = $request->file_url;
        $filename = $request->filename ?? 'document.pdf';

        $companyId = auth()->user()->company_id;
        if ($request->hasFile('file')) {
            $file     = $request->file('file');
            $filename = $request->filename ?? $file->getClientOriginalName();
            $path     = $file->store("invoices/{$companyId}", 'public');
            $fileUrl  = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        if (!$fileUrl) {
            return response()->json(['success' => false, 'error' => 'No file or file URL provided.'], 422);
        }

        try {
            $ms = $this->dispatchFile($service, $phone, $fileUrl, $filename, $request->caption ?? '', $sessionId);
            if ($ms !== null) {
                if ($service) WaOtpLog::create([
                    'company_id'  => $service->company_id,
                    'service_id'  => $service->id,
                    'phone'       => $phone,
                    'action'      => 'invoice_share',
                    'ip_address'  => $request->ip(),
                    'domain'      => 'dashboard-invoice',
                    'response_ms' => $ms,
                ]);
                return response()->json(['success' => true, 'phone' => $phone, 'file_url' => $fileUrl, 'ms' => $ms]);
            }
            return response()->json(['success' => false, 'error' => 'Delivery failed. Check channel settings.'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
