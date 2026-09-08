<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PrebuiltTemplate;
use App\Modules\WaChat\Models\WaApiConfig;
use App\Modules\WaChat\Models\WaOtpCode;
use App\Modules\WaChat\Models\WaOtpLog;
use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Services\OpenWaMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WaOtpServiceController extends Controller
{
    /** Per-company X-API-Key for the open-wa gateway. */
    private function apiKey(WaOtpService $service): string
    {
        return (string) (optional($service->company)->wa_chat_token ?? '');
    }

    /** Send a plain-text message via the WA Chat (open-wa) gateway. Returns response time in ms or null on failure. */
    private function dispatchText(WaOtpService $service, string $phone, string $message, ?string $sessionId = null): ?int
    {
        $sid    = $sessionId ?: ($service->session_id ?: 'default');
        $chatId = preg_replace('/[^0-9]/', '', $phone) . '@c.us';
        $start  = microtime(true);

        try {
            $res = app(OpenWaMessageService::class)->sendText($sid, $this->apiKey($service), $chatId, $message);
            return $res->successful() ? (int) ((microtime(true) - $start) * 1000) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Send a document/file via the WA Chat (open-wa) gateway. Returns ms or null on failure. */
    private function dispatchFile(WaOtpService $service, string $phone, string $fileUrl, string $filename, string $caption, ?string $sessionId = null): ?int
    {
        $sid    = $sessionId ?: ($service->session_id ?: 'default');
        $chatId = preg_replace('/[^0-9]/', '', $phone) . '@c.us';
        $start  = microtime(true);

        try {
            $res = app(OpenWaMessageService::class)->sendMedia($sid, $this->apiKey($service), $chatId, 'document', array_filter([
                'url'      => $fileUrl,
                'filename' => $filename,
                'caption'  => $caption ?: null,
            ], fn ($v) => $v !== null));
            return $res->successful() ? (int) ((microtime(true) - $start) * 1000) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Company-level service settings ────────────────────────────────────────

    public function show(): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->first();
        // `api_token` is $hidden on the model; the company manages its own token
        // here, so surface it (and only here) for display / copy.
        return response()->json(['data' => $service?->makeVisible('api_token')]);
    }

    public function storeOrUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active'        => 'boolean',
            'allowed_domains'  => 'nullable|array',
            'allowed_packages' => 'nullable|array',
        ]);

        $companyId = auth()->user()->company_id;
        $service = WaOtpService::updateOrCreate(['company_id' => $companyId], $data);
        return response()->json(['message' => 'OTP service saved.', 'data' => $service->makeVisible('api_token')]);
    }

    public function resetToken(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $service = WaOtpService::firstOrCreate(['company_id' => $companyId]);
        $service->update([
            'api_token'            => Str::random(64),
            'api_token_created_at' => now(),
        ]);
        return response()->json(['message' => 'Token regenerated.', 'data' => $service->makeVisible('api_token')]);
    }

    public function stopToken(): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->firstOrFail();
        $service->update(['is_active' => false, 'api_token' => null]);
        return response()->json(['message' => 'Token revoked and service deactivated.']);
    }

    public function logs(Request $request): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->firstOrFail();

        $query = WaOtpLog::where('service_id', $service->id)
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->string('session_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderBy('created_at', 'desc');

        $perPage = min(max((int) $request->integer('per_page', 50), 1), 5000);

        return response()->json($query->paginate($perPage));
    }

    // ── Named API configs (Auth OTP API / Utility / Invoice Share tabs) ────────

    /** GET /otp-service/configs?kind=auth|utility|invoice */
    public function configs(Request $request): JsonResponse
    {
        $configs = WaApiConfig::where('company_id', auth()->user()->company_id)
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->with('prebuiltTemplate:id,name,type,language,content')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $configs]);
    }

    public function storeConfig(Request $request): JsonResponse
    {
        $data = $this->validateConfig($request);
        $data['company_id'] = auth()->user()->company_id;

        $config = WaApiConfig::create($data);

        return response()->json(['message' => 'Config created.', 'data' => $config->load('prebuiltTemplate:id,name,type,language')], 201);
    }

    public function updateConfig(Request $request, int $id): JsonResponse
    {
        $config = WaApiConfig::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $data = $this->validateConfig($request, $config);

        $config->update($data);

        return response()->json(['message' => 'Config updated.', 'data' => $config->load('prebuiltTemplate:id,name,type,language')]);
    }

    public function destroyConfig(int $id): JsonResponse
    {
        WaApiConfig::where('company_id', auth()->user()->company_id)->findOrFail($id)->delete();
        return response()->json(['message' => 'Config deleted.']);
    }

    /**
     * GET /otp-service/configs/{id}/stats — per-config usage for the "Stats" action.
     */
    public function configStats(int $id): JsonResponse
    {
        $config = WaApiConfig::where('company_id', auth()->user()->company_id)->findOrFail($id);

        $byAction = WaOtpLog::where('config_id', $config->id)
            ->selectRaw('action, COUNT(*) as c')
            ->groupBy('action')
            ->pluck('c', 'action');

        $stats = [
            'total'        => (int) $byAction->sum(),
            'by_action'    => $byAction,
            'last_used_at' => WaOtpLog::where('config_id', $config->id)->max('created_at'),
            'last_30_days' => WaOtpLog::where('config_id', $config->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];

        if ($config->kind === 'auth') {
            $stats['by_code_status'] = WaOtpCode::where('config_id', $config->id)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status');
        }

        return response()->json(['data' => $stats]);
    }

    /**
     * GET /otp-service/prebuilt-templates?type=auth|utility — read-only list of
     * the superadmin library for the config "template" dropdown.
     */
    public function prebuiltTemplates(Request $request): JsonResponse
    {
        $templates = PrebuiltTemplate::active()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderBy('name')
            ->orderBy('language')
            ->get(['id', 'name', 'type', 'language', 'content', 'variables']);

        return response()->json(['data' => $templates]);
    }

    /**
     * GET /otp-service/sessions — the company's WhatsApp Chat (open-wa) sessions,
     * fetched live from the gateway so the config "session" dropdown lists the
     * real session ids. `waha_sessions` is not relied on because sessions created
     * straight through the gateway never land there.
     */
    public function sessions(): JsonResponse
    {
        $company = auth()->user()->company;
        $apiKey  = (string) ($company?->wa_chat_token ?? '');
        $base    = rtrim((string) config('services.open_wa.base_url'), '/');

        $rows = [];
        try {
            $res = Http::withHeaders(['X-API-Key' => $apiKey])->timeout(10)->get("{$base}/sessions");
            if ($res->successful()) {
                $rows = $res->json('data', $res->json() ?? []);
            }
        } catch (\Throwable) {
            $rows = [];
        }

        $connected = ['ready', 'connected', 'working', 'authenticated'];

        $sessions = collect(is_array($rows) ? $rows : [])
            ->map(function ($s) use ($connected, $company) {
                $id = $s['id'] ?? $s['name'] ?? $s['sessionId'] ?? null;
                if (!filled($id)) {
                    return null;
                }

                $row = [
                    'id'           => $id,
                    'display_name' => $s['displayName'] ?? $s['name'] ?? null,
                    'phone'        => $s['phone'] ?? ($s['me']['id'] ?? null),
                    'status'       => $s['status'] ?? null,
                    'connected'    => in_array(strtolower((string) ($s['status'] ?? '')), $connected, true),
                    'gateway_created_at' => $s['createdAt'] ?? $s['created_at'] ?? null,
                    'session_token'      => $s['token'] ?? $s['apiKey'] ?? null,
                ];

                // Mirror the gateway session into waha_sessions so we keep a
                // local record (and a first-seen timestamp) even for sessions
                // the gateway created directly.
                if ($company) {
                    $local = \App\Modules\WaChat\Models\WahaSession::updateOrCreate(
                        ['company_id' => $company->id, 'session_name' => $id],
                        array_filter([
                            'display_name'       => $row['display_name'],
                            'phone'              => $row['phone'],
                            'status'             => $row['connected'] ? 'connected' : ($row['status'] ?: 'disconnected'),
                            'last_seen_at'       => now(),
                            'gateway_created_at' => $row['gateway_created_at'] ? \Illuminate\Support\Carbon::parse($row['gateway_created_at']) : null,
                            'session_token'      => $row['session_token'],
                        ], fn ($v) => $v !== null),
                    );
                    $row['created_at'] = $local->created_at?->toISOString();
                }

                return $row;
            })
            ->filter()
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /**
     * Validate a config payload against the rules for its `kind`. On update the
     * existing row supplies the `kind` (it cannot be changed) and the unique
     * name is scoped to (company, kind) ignoring the current row.
     */
    private function validateConfig(Request $request, ?WaApiConfig $existing = null): array
    {
        $kind = $existing?->kind ?? $request->input('kind');
        if (!in_array($kind, WaApiConfig::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Invalid config kind.']);
        }

        $companyId = auth()->user()->company_id;
        $req = $existing === null ? 'required' : 'sometimes';

        $rules = [
            'name' => [
                $req, 'string', 'max:100',
                Rule::unique('wa_api_configs', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->where('kind', $kind))
                    ->ignore($existing?->id),
            ],
            'session_id' => ['nullable', 'string', 'max:100'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer'],
        ];

        if ($kind === 'auth') {
            $rules['prebuilt_template_id'] = [$req, $this->templateExistsRule('auth')];
            $rules['otp_length']           = [$req, 'integer', 'min:4', 'max:10'];
            $rules['otp_expiry_minutes']   = [$req, 'integer', 'min:1', 'max:60'];
            $rules['max_attempts']         = ['nullable', 'integer', 'min:1', 'max:10'];
        }

        if ($kind === 'utility') {
            $rules['prebuilt_template_id'] = ['nullable', 'required_without:custom_content', $this->templateExistsRule('utility')];
            $rules['custom_content']       = ['nullable', 'required_without:prebuilt_template_id', 'string', 'max:2000'];
        }

        if ($kind === 'invoice') {
            // The file URL and filename are passed per request via the public
            // /api-service/invoice-share API — the config only carries a name,
            // session and an optional default caption.
            $rules['caption'] = ['nullable', 'string', 'max:500'];
        }

        $data = $request->validate($rules);
        $data['kind'] = $kind;

        // Clear the alternate field so a config never carries both a template and
        // custom content.
        if ($kind === 'utility') {
            if (!empty($data['prebuilt_template_id'])) {
                $data['custom_content'] = null;
            } elseif (!empty($data['custom_content'])) {
                $data['prebuilt_template_id'] = null;
            }
        }

        return $data;
    }

    /** A Rule closure: the id must be an active prebuilt_templates row of the given type. */
    private function templateExistsRule(string $type): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($type) {
            if ($value === null || $value === '') {
                return;
            }
            $ok = PrebuiltTemplate::active()->where('type', $type)->whereKey($value)->exists();
            if (!$ok) {
                $fail("The selected template is not a valid active {$type} template.");
            }
        };
    }

    // ── Dashboard test / manual sends ─────────────────────────────────────────

    public function testSend(Request $request): JsonResponse
    {
        $request->validate([
            'phone'     => 'required|string',
            'service'   => 'nullable|string|max:100',
            'variables' => 'nullable|array',
        ]);

        $companyId = auth()->user()->company_id;
        $service = WaOtpService::firstOrCreate(['company_id' => $companyId]);

        $config = $this->resolveConfig($companyId, 'auth', $request->input('service'));

        $length   = $config?->otp_length ?? 6;
        $expiry   = $config?->otp_expiry_minutes ?? 10;
        $otp      = str_pad((string) random_int(0, (int) pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        $template = $config?->prebuiltTemplate?->content ?? 'Your OTP is {{otp}}. Valid for {{expiry}} minutes.';
        $message  = $this->fillPlaceholders($template, array_merge(
            $this->testVariables($request),
            ['otp' => $otp, 'code' => $otp, 'expiry' => $expiry],
        ));
        $phone    = preg_replace('/[^0-9]/', '', $request->phone);
        $sid      = $config?->session_id ?: $service->session_id;

        try {
            $ms = $this->dispatchText($service, $phone, $message, $sid);
            if ($ms !== null) {
                WaOtpLog::create([
                    'company_id'  => $service->company_id,
                    'service_id'  => $service->id,
                    'config_id'   => $config?->id,
                    'session_id'  => $sid ?: ($service->session_id ?: null),
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

    /** Send a utility / transactional message to a phone number from the dashboard. */
    public function utilityMessageSend(Request $request): JsonResponse
    {
        $request->validate([
            'phone'      => 'required|string',
            'service'    => 'nullable|string|max:100',
            'message'    => 'nullable|string|max:2000',
            'session_id' => 'nullable|string|max:100',
            'variables'  => 'nullable|array',
        ]);

        $companyId = auth()->user()->company_id;
        $service = WaOtpService::where('company_id', $companyId)->first();
        $config  = $this->resolveConfig($companyId, 'utility', $request->input('service'));

        $company = auth()->user()->company;
        $message = $request->message ?: $config?->resolveContent();
        if ($message) {
            $message = $this->fillPlaceholders($message, array_merge($this->testVariables($request), [
                'company'        => $company->name ?? 'Us',
                'company_name'   => $company->name ?? 'Us',
                'business name'  => $company->name ?? 'Us',
            ]));
        }

        if (!$message) {
            return response()->json(['success' => false, 'error' => 'No message content.'], 422);
        }

        $sessionId = $request->session_id ?: ($config?->session_id ?: ($service?->session_id ?? 'default'));
        $phone = preg_replace('/[^0-9]/', '', $request->phone);

        try {
            $ms = $this->dispatchText($service, $phone, $message, $sessionId);
            if ($ms !== null) {
                if ($service) WaOtpLog::create([
                    'company_id'  => $service->company_id,
                    'service_id'  => $service->id,
                    'config_id'   => $config?->id,
                    'session_id'  => $sessionId ?: null,
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

    /** Share an invoice / document file to a customer's WhatsApp number from the dashboard. */
    public function invoiceShare(Request $request): JsonResponse
    {
        $request->validate([
            'phone'      => 'required|string',
            'service'    => 'nullable|string|max:100',
            'file_url'   => 'nullable|string',
            'file'       => 'nullable|file|max:20480',
            'caption'    => 'nullable|string|max:500',
            'filename'   => 'nullable|string|max:200',
            'session_id' => 'nullable|string|max:100',
        ]);

        $companyId = auth()->user()->company_id;
        $service = WaOtpService::where('company_id', $companyId)->first();
        $config  = $this->resolveConfig($companyId, 'invoice', $request->input('service'));

        $sessionId = $request->session_id ?: ($config?->session_id ?: ($service?->session_id ?? 'default'));
        $phone    = preg_replace('/[^0-9]/', '', $request->phone);
        $fileUrl  = $request->file_url ?: $config?->file_url;
        $filename = $request->filename ?: ($config?->filename ?? 'document.pdf');
        $caption  = $request->caption ?? $config?->caption ?? '';

        if ($request->hasFile('file')) {
            $file     = $request->file('file');
            $filename = $request->filename ?: $file->getClientOriginalName();
            $path     = $file->store("invoices/{$companyId}", 'public');
            $fileUrl  = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        if (!$fileUrl) {
            return response()->json(['success' => false, 'error' => 'No file or file URL provided.'], 422);
        }

        try {
            $ms = $this->dispatchFile($service, $phone, $fileUrl, $filename, $caption, $sessionId);
            if ($ms !== null) {
                if ($service) WaOtpLog::create([
                    'company_id'  => $service->company_id,
                    'service_id'  => $service->id,
                    'config_id'   => $config?->id,
                    'session_id'  => $sessionId ?: null,
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

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Resolve a named config for the company, or the first active one of that kind. */
    private function resolveConfig(int $companyId, string $kind, ?string $name): ?WaApiConfig
    {
        $query = WaApiConfig::where('company_id', $companyId)->where('kind', $kind)->where('is_active', true);

        if (filled($name)) {
            $query->where('name', $name);
        } else {
            $query->orderBy('sort_order')->orderBy('id');
        }

        return $query->with('prebuiltTemplate:id,name,type,language,content')->first();
    }

    /** Replace {{key}} / {{ key }} tokens from a flat map, leaving unknown tokens intact. */
    private function fillPlaceholders(string $template, array $map): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function ($m) use ($map) {
            $key = strtolower(trim($m[1]));
            return array_key_exists($key, $map) ? (string) $map[$key] : $m[0];
        }, $template);
    }

    /** Caller-supplied test variables, keyed by (lower-cased) name. */
    private function testVariables(Request $request): array
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
}
