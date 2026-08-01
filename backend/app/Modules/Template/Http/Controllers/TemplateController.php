<?php
namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Services\TemplateService;
use App\Http\Controllers\Controller;
use App\Models\WaTemplate;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TemplateController extends Controller
{
    public function __construct(private TemplateService $templates) {}

    // ── List ───────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $paginated = $this->templates->list(auth()->user()->company_id, $request->all());
        return response()->json([
            'templates' => $paginated->items(),
            'total'     => $paginated->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['template' => $this->templates->show($id, auth()->user()->company_id)]);
    }

    // ── Create template here + push to Meta ──────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);

        $company = auth()->user()->company;

        // Save locally first (via service, so create/update share one source of truth)
        $template = $this->templates->create(auth()->user()->company_id, [
            ...$d,
            'status' => 'pending',
        ]);

        $this->submitToMeta($template, $company, $d);

        return response()->json(['template' => $template->fresh()], 201);
    }

    // ── Update template + re-submit to Meta ───────────────────────────────
    public function update(int $id, Request $request): JsonResponse
    {
        $d = $this->validated($request, isUpdate: true);

        $template = $this->templates->update($id, auth()->user()->company_id, [
            ...$d,
            'status' => 'pending', // any edit forces re-review
        ]);

        $company = auth()->user()->company;
        $this->submitToMeta($template, $company, $d, isUpdate: true);

        return response()->json(['template' => $template->fresh()]);
    }

    // ── Upload a sample IMAGE/VIDEO/DOCUMENT for Meta's header review ─────
    // Meta's resumable upload API: create an upload session, then PUT the file bytes,
    // returns a header_handle ("h" value) referenced when the template is submitted.
    public function uploadHeaderMedia(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:16384']]); // 16MB safety cap

        $company = auth()->user()->company;
        if (!$company->decrypt_wa_access_token || !$company->wa_app_id) {
            return response()->json(['message' => 'WhatsApp app credentials not configured.'], 422);
        }

        $file = $request->file('file');

        // Store locally too, so "edit" can show what was previously uploaded
        $path = $file->store('template-headers', 'public');
        $publicUrl = Storage::disk('public')->url($path);

        // Step 1: create upload session
        $session = Http::withToken($company->decrypt_wa_access_token)
            ->post("https://graph.facebook.com/v25.0/{$company->wa_app_id}/uploads", [
                'file_length' => $file->getSize(),
                'file_type'   => $file->getMimeType(),
            ]);

        if ($session->failed()) {
            return response()->json(['message' => $session->json('error.message') ?? 'Failed to start upload session.'], 422);
        }

        $uploadSessionId = $session->json('id'); // format: "upload:XYZ"

        // Step 2: PUT the raw file bytes to the session endpoint
        $upload = Http::withHeaders([
                'Authorization' => 'OAuth ' . $company->decrypt_wa_access_token,
                'file_offset'   => '0',
            ])
            ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
            ->post("https://graph.facebook.com/v25.0/{$uploadSessionId}");

        if ($upload->failed()) {
            return response()->json(['message' => $upload->json('error.message') ?? 'Failed to upload sample media.'], 422);
        }

        return response()->json([
            'header_handle'      => $upload->json('h'),
            'header_sample_url'  => $publicUrl,
            'header_sample_path' => $path,
        ]);
    }

    // ── Validation shared by store/update ──────────────────────────────
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $d = $request->validate([
            'name'           => [$isUpdate ? 'sometimes' : 'required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:100'],
            'category'       => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'language'       => ['required', 'string', 'max:10'],
            'body'           => ['required', 'string', 'max:1024'],
            'body_examples'  => ['nullable', 'array'],
            'body_examples.*'=> ['string', 'max:255'],
            'header_format'  => ['nullable', 'in:TEXT,IMAGE,VIDEO,DOCUMENT'],
            'header'         => ['nullable', 'string', 'max:60'],
            'header_example' => ['nullable', 'string', 'max:255'],
            'header_handle'  => ['nullable', 'string'],
            'footer'         => ['nullable', 'string', 'max:60'],
            'buttons'        => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['required_with:buttons', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
            'buttons.*.text' => ['required_with:buttons', 'string', 'max:25'],
            'buttons.*.url'  => ['nullable', 'url'],
        ]);

        $d['header_format'] = $d['header_format'] ?? 'TEXT';

        // Media headers don't carry inline header text — only handle + example are relevant
        if ($d['header_format'] !== 'TEXT') {
            $d['header'] = null;
        }

        return $d;
    }

    // ── Push a create or update to Meta, mirror status/errors back locally ─
    private function submitToMeta(WaTemplate $template, $company, array $d, bool $isUpdate = false): void
    {
        if (!$company->decrypt_wa_access_token || !$company->wa_business_account_id) {
            return;
        }

        $components = $this->buildComponents($d);

        if ($isUpdate) {
            // Meta edits an existing template by its ID, PATCH-style, category/components only
            $response = Http::withToken($company->decrypt_wa_access_token)
                ->post("https://graph.facebook.com/v25.0/{$template->wa_template_id}", [
                    'category'   => $d['category'],
                    'components' => $components,
                ]);
        } else {
            $response = Http::withToken($company->decrypt_wa_access_token)
                ->post("https://graph.facebook.com/v25.0/{$company->wa_business_account_id}/message_templates", [
                    'name'       => $d['name'],
                    'category'   => $d['category'],
                    'language'   => $d['language'],
                    'components' => $components,
                ]);
        }

        if ($response->successful()) {
            $data = $response->json();
            $template->update([
                'wa_template_id'   => $data['id'] ?? $template->wa_template_id,
                'status'           => strtolower($data['status'] ?? 'pending'),
                'rejection_reason' => null,
            ]);
        } else {
            $template->update([
                'status'           => 'error',
                'rejection_reason' => $response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta API error',
            ]);
        }
    }

    // ── Build Meta component array from our fields ────────────────────────
    private function buildComponents(array $d): array
    {
        $components = [];

        $headerFormat = $d['header_format'] ?? 'TEXT';

        if ($headerFormat === 'TEXT' && !empty($d['header'])) {
            $header = [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $d['header'],
            ];
            if (!empty($d['header_example'])) {
                $header['example'] = ['header_text' => [$d['header_example']]];
            }
            $components[] = $header;
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT']) && !empty($d['header_handle'])) {
            $components[] = [
                'type'   => 'HEADER',
                'format' => $headerFormat,
                'example'=> ['header_handle' => [$d['header_handle']]],
            ];
        }

        $body = [
            'type' => 'BODY',
            'text' => $d['body'],
        ];
        if (!empty($d['body_examples'])) {
            // Meta expects an array of example sets — we only ever send one
            $body['example'] = ['body_text' => [array_values($d['body_examples'])]];
        }
        $components[] = $body;

        if (!empty($d['footer'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $d['footer'],
            ];
        }

        if (!empty($d['buttons'])) {
            $buttons = collect($d['buttons'])->map(fn($b) => array_filter([
                'type' => $b['type'] ?? 'QUICK_REPLY',
                'text' => $b['text'],
                'url'  => $b['type'] === 'URL' ? ($b['url'] ?? null) : null,
            ], fn($v) => !is_null($v)))->toArray();

            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => $buttons,
            ];
        }

        return $components;
    }

    // ── Delete template from platform + Meta ─────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $template = WaTemplate::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $company = auth()->user()->company;

        if ($template->wa_template_id && $company->decrypt_wa_access_token && $company->wa_business_account_id) {
            Http::withToken($company->decrypt_wa_access_token)
                ->delete("https://graph.facebook.com/v25.0/{$company->wa_business_account_id}/message_templates", [
                    'hsm_id' => $template->wa_template_id,
                    'name'   => $template->name,
                ]);
        }

        if ($template->header_sample_path) {
            Storage::disk('public')->delete($template->header_sample_path);
        }

        $template->delete();
        return response()->json(['message' => 'Template deleted from platform and Meta.']);
    }

    // ── Sync all templates from Meta (bulk, used by the "Sync from Meta" button) ─
    public function syncFromMeta(): JsonResponse
    {
        $company = auth()->user()->company;

        if (!$company->decrypt_wa_access_token || !$company->wa_business_account_id) {
            return response()->json(['message' => 'WhatsApp credentials not set.'], 422);
        }

        $response = Http::withToken($company->decrypt_wa_access_token)
            ->get("https://graph.facebook.com/v25.0/{$company->wa_business_account_id}/message_templates", [
                'fields' => 'id,name,category,language,status,rejected_reason,components',
                'limit'  => 100,
            ]);

        if ($response->failed()) {
            return response()->json(['message' => $response->json('error.message')], 422);
        }

        $updated = 0;
        foreach ($response->json('data', []) as $meta) {
            $components = collect($meta['components'] ?? []);
            $bodyComponent   = $components->firstWhere('type', 'BODY');
            $headerComponent = $components->firstWhere('type', 'HEADER');

            WaTemplate::updateOrCreate(
                ['company_id' => $company->id, 'name' => $meta['name']],
                [
                    'wa_template_id'   => $meta['id'],
                    'category'         => $meta['category'],
                    'language'         => $meta['language'],
                    'body'             => $bodyComponent['text'] ?? '',
                    'body_examples'    => $bodyComponent['example']['body_text'][0] ?? null,
                    'header_format'    => $headerComponent['format'] ?? 'TEXT',
                    'header'           => $headerComponent['format'] === 'TEXT' ? ($headerComponent['text'] ?? null) : null,
                    'status'           => strtolower($meta['status']),
                    'rejection_reason' => $meta['rejected_reason'] ?? null,
                ]
            );
            $updated++;
        }

        return response()->json(['message' => "Synced {$updated} templates from Meta."]);
    }
}

// class TemplateController extends Controller
// {
//     public function __construct(private readonly TemplateService $service) {}

//     public function index(Request $request): JsonResponse
//     {
//         $templates = $this->service->list(auth()->user()->company_id, $request->all());
//         return response()->json($templates);
//     }

//     public function show(int $id): JsonResponse
//     {
//         $t = $this->service->show($id, auth()->user()->company_id);
//         return response()->json(['template' => $t]);
//     }

//     public function store(Request $request): JsonResponse
//     {
//         $data = $request->validate([
//             'name'               => ['required','string','max:100'],
//             'wa_template_id'     => ['nullable','string'],
//             'wa_phone_number_id' => ['nullable','integer','exists:wa_phone_numbers,id'],
//             'category'           => ['required','in:authentication,marketing,utility'],
//             'language'           => ['nullable','string','max:10'],
//             'body'               => ['required','string'],
//             'header'             => ['nullable','string','max:500'],
//             'footer'             => ['nullable','string','max:300'],
//             'variables'          => ['nullable','array'],
//             'status'             => ['nullable','in:pending,approved,rejected'],
//         ]);
//         $t = $this->service->create(auth()->user()->company_id, $data);
//         return response()->json(['message' => 'Template created.', 'template' => $t], 201);
//     }

//     public function update(Request $request, int $id): JsonResponse
//     {
//         $data = $request->validate([
//             'name'               => ['sometimes','string','max:100'],
//             'wa_template_id'     => ['nullable','string'],
//             'wa_phone_number_id' => ['nullable','integer','exists:wa_phone_numbers,id'],
//             'category'           => ['sometimes','in:authentication,marketing,utility'],
//             'language'           => ['nullable','string','max:10'],
//             'body'               => ['sometimes','string'],
//             'header'             => ['nullable','string','max:500'],
//             'footer'             => ['nullable','string','max:300'],
//             'variables'          => ['nullable','array'],
//             'status'             => ['sometimes','in:pending,approved,rejected'],
//         ]);
//         $t = $this->service->update($id, auth()->user()->company_id, $data);
//         return response()->json(['message' => 'Template updated.', 'template' => $t]);
//     }

//     public function destroy(int $id): JsonResponse
//     {
//         $this->service->delete($id, auth()->user()->company_id);
//         return response()->json(['message' => 'Template deleted.']);
//     }

//     public function syncFromMeta(int $id): JsonResponse
//     {
//         $t = $this->service->syncFromMeta($id, auth()->user()->company_id);
//         return response()->json(['message' => 'Synced from Meta.', 'template' => $t]);
//     }
// }
