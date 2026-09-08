<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PrebuiltTemplate;
use App\Modules\WaCloud\Models\WaCloudApiConfig;
use App\Modules\WaCloud\Models\WaCloudOtpCode;
use App\Modules\WaCloud\Models\WaCloudOtpLog;
use App\Modules\WaCloud\Services\WaCloudTemplateService;
use App\Support\Meta\MetaMediaUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WA Cloud Api Service configs (JWT). Each config is backed by a Meta message
 * template that is (re)submitted on save.
 */
class WaCloudApiConfigController extends Controller
{
    public function __construct(private readonly WaCloudTemplateService $templates) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function scoped()
    {
        return WaCloudApiConfig::where('company_id', $this->companyId());
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $configs = $this->scoped()
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->with('prebuiltTemplate:id,name,type,language,content')
            ->orderBy('kind')->orderBy('sort_order')->orderBy('name')
            ->get();

        return response()->json(['data' => $configs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateConfig($request);
        $data['company_id'] = $this->companyId();

        $data = array_merge($data, $this->derivedTemplateFields($data));
        $data['template_status'] = 'draft';

        $config = WaCloudApiConfig::create($data);

        $config = $this->submitIfPossible($config);

        return response()->json([
            'message' => 'Config created.',
            'data'    => $config->load('prebuiltTemplate:id,name,type,language,content'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $config = $this->scoped()->findOrFail($id);

        if (in_array($config->template_status, WaCloudApiConfig::LOCKED_STATUSES, true)) {
            return response()->json([
                'message' => 'Approved or pending templates cannot be edited on Meta. Delete and recreate this service to change it.',
            ], 422);
        }

        $data = $this->validateConfig($request, $config);
        $data = array_merge($data, $this->derivedTemplateFields($data, $config));

        $config->update($data);
        $config = $this->submitIfPossible($config->fresh());

        return response()->json([
            'message' => 'Config updated.',
            'data'    => $config->load('prebuiltTemplate:id,name,type,language,content'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $config = $this->scoped()->findOrFail($id);

        $this->templates->deleteFromMeta($config);

        if ($config->header_sample_path) {
            Storage::disk('public')->delete($config->header_sample_path);
        }

        $config->delete();

        return response()->json(['message' => 'Config deleted.']);
    }

    // ── Meta template lifecycle ──────────────────────────────────────────────

    public function submit(int $id): JsonResponse
    {
        $config  = $this->scoped()->findOrFail($id);
        $company = auth()->user()->company;

        $missing = $this->templates->missingCredentials($company, $config);
        if ($missing !== []) {
            return response()->json([
                'message' => 'Connect your WhatsApp Cloud API credentials in WA Cloud → Settings before submitting a template.',
                'missing' => $missing,
            ], 422);
        }

        $config = $this->templates->submit($config);

        return response()->json(['message' => 'Submitted to Meta.', 'data' => $config]);
    }

    public function sync(int $id): JsonResponse
    {
        $config = $this->templates->sync($this->scoped()->findOrFail($id));

        return response()->json(['message' => 'Synced from Meta.', 'data' => $config]);
    }

    public function uploadHeaderMedia(int $id, Request $request): JsonResponse
    {
        $config = $this->scoped()->findOrFail($id);

        if ($config->kind !== 'invoice') {
            return response()->json(['message' => 'Only invoice services carry a document sample.'], 422);
        }

        $request->validate(['file' => ['required', 'file', 'max:16384']]);

        $meta = MetaMediaUploader::upload($request->file('file'), auth()->user()->company, 'wa-cloud-invoice-samples');

        if (isset($meta['error'])) {
            $config->update(['template_status' => 'error', 'rejection_reason' => $meta['error']]);
            return response()->json(['message' => $meta['error']], 422);
        }

        if ($config->header_sample_path) {
            Storage::disk('public')->delete($config->header_sample_path);
        }

        $config->update([
            'header_format'      => 'DOCUMENT',
            'header_handle'      => $meta['handle'],
            'header_sample_path' => $meta['path'],
            'header_sample_url'  => $meta['url'],
            'template_status'    => $config->template_status === 'error' ? 'draft' : $config->template_status,
            'rejection_reason'   => $config->template_status === 'error' ? null : $config->rejection_reason,
        ]);

        return response()->json([
            'header_handle'     => $meta['handle'],
            'header_sample_url' => $meta['url'],
        ]);
    }

    public function deleteHeaderMedia(int $id): JsonResponse
    {
        $config = $this->scoped()->findOrFail($id);

        if ($config->header_sample_path) {
            Storage::disk('public')->delete($config->header_sample_path);
        }

        $config->update([
            'header_handle'      => null,
            'header_sample_path' => null,
            'header_sample_url'  => null,
        ]);

        return response()->json(['message' => 'Sample document removed.']);
    }

    // ── Stats + library ──────────────────────────────────────────────────────

    public function stats(int $id): JsonResponse
    {
        $config = $this->scoped()->findOrFail($id);

        $byAction = WaCloudOtpLog::where('config_id', $config->id)
            ->selectRaw('action, COUNT(*) as c')->groupBy('action')->pluck('c', 'action');

        $stats = [
            'total'        => (int) $byAction->sum(),
            'by_action'    => $byAction,
            'last_used_at' => WaCloudOtpLog::where('config_id', $config->id)->max('created_at'),
            'last_30_days' => WaCloudOtpLog::where('config_id', $config->id)
                ->where('created_at', '>=', now()->subDays(30))->count(),
        ];

        if ($config->kind === 'auth') {
            $stats['by_code_status'] = WaCloudOtpCode::where('config_id', $config->id)
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        }

        return response()->json(['data' => $stats]);
    }

    public function prebuiltTemplates(Request $request): JsonResponse
    {
        $templates = PrebuiltTemplate::active()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderBy('name')->orderBy('language')
            ->get(['id', 'name', 'type', 'language', 'content', 'variables']);

        return response()->json(['data' => $templates]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Save + push to Meta when the company has the credentials; otherwise leave a draft. */
    private function submitIfPossible(WaCloudApiConfig $config): WaCloudApiConfig
    {
        $missing = $this->templates->missingCredentials(auth()->user()->company, $config);

        if ($missing !== []) {
            $config->update([
                'template_status'  => 'draft',
                'rejection_reason' => 'Not submitted — missing ' . implode(', ', $missing) . '. Submit again after connecting your WhatsApp Cloud API credentials.',
            ]);
            return $config->fresh();
        }

        return $this->templates->submit($config);
    }

    /**
     * Fields derived from the validated payload: the registered template name,
     * category/language, and (for utility/invoice) the compiled positional body.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function derivedTemplateFields(array $data, ?WaCloudApiConfig $existing = null): array
    {
        $kind = $data['kind'] ?? $existing?->kind;

        $out = [
            'template_category' => $kind === 'auth' ? 'AUTHENTICATION' : 'UTILITY',
        ];

        // Registered name — stable once created.
        if (!$existing || !$existing->template_name) {
            $slugKind = Str::slug($kind, '_');
            $slugName = Str::slug($data['name'] ?? $existing?->name ?? 'service', '_');
            $out['template_name'] = substr("{$slugKind}_{$slugName}", 0, 110) . '_' . Str::lower(Str::random(4));
        }

        // Language: explicit, else from the chosen prebuilt template, else keep/en.
        $prebuiltId = $data['prebuilt_template_id'] ?? $existing?->prebuilt_template_id;
        $prebuilt   = $prebuiltId ? PrebuiltTemplate::find($prebuiltId) : null;
        $out['template_language'] = $data['template_language']
            ?? $prebuilt?->language
            ?? $existing?->template_language
            ?? 'en';

        if ($kind === 'auth') {
            return $out;
        }

        // utility / invoice — compile the named-placeholder body to positional.
        $source = $data['custom_content'] ?? null;
        if (!$source && $prebuilt) {
            $source = $prebuilt->content;
        }
        $source ??= $existing?->resolveContent() ?? '';

        $names = PrebuiltTemplate::extractVariables($source);

        $bodyText = $source;
        foreach ($names as $i => $name) {
            $bodyText = preg_replace('/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/', '{{' . ($i + 1) . '}}', $bodyText);
        }

        $given = [];
        foreach ((array) ($data['body_examples'] ?? []) as $k => $v) {
            $given[strtolower(trim((string) $k))] = (string) $v;
        }

        $examples = [];
        foreach ($names as $name) {
            $key = strtolower(trim($name));
            $examples[] = $given[$key] ?? ('sample_' . $key);
        }

        $out['body_text']           = $bodyText;
        $out['body_variable_names'] = $names;
        $out['body_examples']       = $examples;

        return $out;
    }

    /** @return array<string, mixed> */
    private function validateConfig(Request $request, ?WaCloudApiConfig $existing = null): array
    {
        $kind = $existing?->kind ?? $request->input('kind');
        if (!in_array($kind, WaCloudApiConfig::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Invalid config kind.']);
        }

        $companyId = $this->companyId();
        $req = $existing === null ? 'required' : 'sometimes';

        $rules = [
            'name' => [
                $req, 'string', 'max:100',
                Rule::unique('wa_cloud_api_configs', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->where('kind', $kind))
                    ->ignore($existing?->id),
            ],
            'template_language' => ['sometimes', 'string', 'max:10'],
            'footer_text'       => ['nullable', 'string', 'max:60'],
            'is_active'         => ['boolean'],
            'sort_order'        => ['integer'],
        ];

        if ($kind === 'auth') {
            $method = $request->input('auth_delivery_method', $existing?->auth_delivery_method);
            $needsApps = in_array($method, ['one_tap', 'zero_tap'], true);

            $rules['prebuilt_template_id']            = ['nullable', $this->templateExistsRule('auth')];
            $rules['otp_length']                      = [$req, 'integer', 'min:4', 'max:10'];
            $rules['otp_expiry_minutes']              = [$req, 'integer', 'min:1', 'max:60'];
            $rules['max_attempts']                    = ['nullable', 'integer', 'min:1', 'max:10'];
            $rules['auth_delivery_method']            = [$req, 'in:copy_code,one_tap,zero_tap'];
            $rules['auth_apps']                       = [Rule::requiredIf($needsApps), 'nullable', 'array', 'max:5'];
            $rules['auth_apps.*.package_name']        = ['required_with:auth_apps', 'string', 'max:224'];
            $rules['auth_apps.*.signature_hash']      = ['required_with:auth_apps', 'string', 'max:50'];
            $rules['auth_add_expiry']                 = ['nullable', 'boolean'];
            $rules['auth_code_expiration_minutes']    = ['nullable', 'integer', 'min:1', 'max:90'];
            $rules['auth_add_security_recommendation'] = ['nullable', 'boolean'];
            $rules['auth_zero_tap_terms_accepted']    = ['nullable', 'boolean'];
        }

        if ($kind === 'utility' || $kind === 'invoice') {
            $rules['prebuilt_template_id'] = ['nullable', 'required_without:custom_content', $this->templateExistsRule('utility')];
            $rules['custom_content']       = ['nullable', 'required_without:prebuilt_template_id', 'string', 'max:1024'];
            $rules['body_examples']        = ['nullable', 'array'];
        }

        $data = $request->validate($rules);
        $data['kind'] = $kind;

        if ($kind === 'auth' && ($data['auth_delivery_method'] ?? null) === 'zero_tap'
            && empty($data['auth_zero_tap_terms_accepted'])) {
            throw ValidationException::withMessages([
                'auth_zero_tap_terms_accepted' => 'You must accept the zero-tap terms to submit this template.',
            ]);
        }

        // A config never carries both a template link and custom content.
        if (in_array($kind, ['utility', 'invoice'], true)) {
            if (!empty($data['prebuilt_template_id'])) {
                $data['custom_content'] = null;
            } elseif (!empty($data['custom_content'])) {
                $data['prebuilt_template_id'] = null;
            }
        }

        return $data;
    }

    private function templateExistsRule(string $type): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($type) {
            if ($value === null || $value === '') {
                return;
            }
            if (!PrebuiltTemplate::active()->where('type', $type)->whereKey($value)->exists()) {
                $fail("The selected template is not a valid active {$type} template.");
            }
        };
    }
}
