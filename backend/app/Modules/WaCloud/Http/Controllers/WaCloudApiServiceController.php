<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaCloud\Models\WaCloudApiConfig;
use App\Modules\WaCloud\Models\WaCloudOtpCode;
use App\Modules\WaCloud\Models\WaCloudOtpLog;
use App\Modules\WaCloud\Models\WaCloudOtpService;
use App\Modules\WaCloud\Services\WaCloudMessageService;
use App\Support\Meta\MetaGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WA Cloud OTP Service — company-level management (JWT). Mirrors
 * WaOtpServiceController's company-level half, minus everything open-wa.
 */
class WaCloudApiServiceController extends Controller
{
    public function __construct(private readonly WaCloudMessageService $messages) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    public function show(): JsonResponse
    {
        $service = WaCloudOtpService::where('company_id', $this->companyId())->first();
        $company = auth()->user()->company;

        return response()->json([
            'data' => $service?->makeVisible('api_token'),
            'meta' => [
                'has_credentials'     => (bool) ($company->wa_phone_id && MetaGraph::accessToken($company) && $company->wa_business_id),
                'wa_phone_id_set'     => (bool) $company->wa_phone_id,
                'wa_business_id_set'  => (bool) $company->wa_business_id,
                'meta_app_id_set'     => (bool) $company->meta_app_id,
            ],
        ]);
    }

    public function storeOrUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active'        => 'boolean',
            'allowed_domains'  => 'nullable|array',
            'allowed_packages' => 'nullable|array',
        ]);

        $service = WaCloudOtpService::updateOrCreate(['company_id' => $this->companyId()], $data);

        return response()->json(['message' => 'OTP service saved.', 'data' => $service->makeVisible('api_token')]);
    }

    public function resetToken(): JsonResponse
    {
        $service = WaCloudOtpService::firstOrCreate(['company_id' => $this->companyId()]);
        $service->update([
            'api_token'            => Str::random(64),
            'api_token_created_at' => now(),
            'is_active'            => true,
        ]);

        return response()->json(['message' => 'Token regenerated.', 'data' => $service->makeVisible('api_token')]);
    }

    public function stopToken(): JsonResponse
    {
        $service = WaCloudOtpService::where('company_id', $this->companyId())->firstOrFail();
        $service->update(['is_active' => false, 'api_token' => null]);

        return response()->json(['message' => 'Token revoked and service deactivated.']);
    }

    public function logs(Request $request): JsonResponse
    {
        $service = WaCloudOtpService::where('company_id', $this->companyId())->firstOrFail();

        $query = WaCloudOtpLog::where('service_id', $service->id)
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('config_id'), fn ($q) => $q->where('config_id', $request->integer('config_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at');

        $perPage = min(max((int) $request->integer('per_page', 50), 1), 5000);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Manual send from the dashboard. `type` ∈ otp|utility|invoice.
     */
    public function testSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'        => 'required|string|max:20',
            'type'         => 'required|in:otp,utility,invoice',
            'service'      => 'nullable|string|max:100',
            'variables'    => 'nullable|array',
            'document_url' => 'nullable|url|required_if:type,invoice',
            'filename'     => 'nullable|string|max:200',
        ]);

        $company = auth()->user()->company;
        if (!$company->wa_phone_id || !MetaGraph::accessToken($company)) {
            return response()->json(['error' => 'Connect your WhatsApp Cloud API credentials first.'], 422);
        }

        $service = WaCloudOtpService::firstOrCreate(['company_id' => $this->companyId()]);
        $kind    = $data['type'] === 'otp' ? 'auth' : $data['type'];

        $config = WaCloudApiConfig::where('company_id', $this->companyId())
            ->where('kind', $kind)
            ->when(filled($data['service'] ?? null), fn ($q) => $q->where('name', $data['service']))
            ->orderBy('sort_order')->orderBy('id')
            ->first();

        if (!$config) {
            return response()->json(['error' => "No {$kind} service configured."], 422);
        }
        if (!$config->isSendable()) {
            return response()->json(['error' => "The \"{$config->name}\" template is not approved yet (status: {$config->template_status})."], 422);
        }

        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        $vars  = is_array($data['variables'] ?? null) ? $data['variables'] : [];
        $otp   = null;

        [$ok, $waMessageId, $error, $ms] = match ($kind) {
            'auth' => (function () use ($company, $config, $phone, &$otp) {
                $len = $config->otp_length ?? 6;
                $otp = str_pad((string) random_int(0, (int) str_repeat('9', $len)), $len, '0', STR_PAD_LEFT);
                return $this->messages->sendAuth($company, $config, $phone, $otp);
            })(),
            'utility' => $this->messages->sendUtility($company, $config, $phone, $vars),
            'invoice' => $this->messages->sendInvoice($company, $config, $phone, $data['document_url'], $data['filename'] ?? null, $vars),
        };

        WaCloudOtpLog::create([
            'company_id'    => $service->company_id,
            'service_id'    => $service->id,
            'config_id'     => $config->id,
            'phone'         => $phone,
            'action'        => $kind === 'auth' ? 'sent' : ($kind === 'invoice' ? 'invoice_share' : 'utility'),
            'wa_message_id' => $waMessageId,
            'error'         => $ok ? null : $error,
            'ip_address'    => $request->ip(),
            'domain'        => 'dashboard-test',
            'response_ms'   => $ms,
        ]);

        return $ok
            ? response()->json(['success' => true, 'phone' => $phone, 'otp' => $otp, 'wa_message_id' => $waMessageId, 'ms' => $ms])
            : response()->json(['success' => false, 'error' => $error ?? 'Send failed.'], 422);
    }
}
