<?php

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Services\TemplateService;
use App\Http\Controllers\Controller;
use App\Models\WaTemplate;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TemplateController extends Controller
{
    public function __construct(private TemplateService $templates) {}

    // ── List / show ────────────────────────────────────────────────────
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

    // ── Create as DRAFT — does NOT push to Meta yet ───────────────────────
    // Media (header/footer/button) is uploaded against this id afterwards,
    // then the frontend calls /submit to actually push to Meta once ready.
    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);

        $template = $this->templates->create(auth()->user()->company_id, [
            ...$d,
            'status' => 'draft',
        ]);

        return response()->json(['template' => $template->fresh()], 201);
    }

    // ── Update a draft or resubmit an existing template ───────────────────
    public function update(int $id, Request $request): JsonResponse
    {
        $d = $this->validated($request, isUpdate: true);

        $template = $this->templates->show($id, auth()->user()->company_id);
        $wasSubmitted = $template->status !== 'draft';

        $template = $this->templates->update($id, auth()->user()->company_id, [
            ...$d,
            // Only force back to draft if it hadn't been submitted yet; if it was
            // already live on Meta, editing should re-submit rather than orphan it as a draft
            'status' => $wasSubmitted ? 'pending' : 'draft',
        ]);

        if ($wasSubmitted) {
            $company = auth()->user()->company;
            $this->submitToMeta($template, $company, $this->mergedPayloadForSubmit($template), isUpdate: true);
        }

        return response()->json(['template' => $template->fresh()]);
    }

    // ── Push a draft to Meta for the first time ───────────────────────────
    public function submit(int $id): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);
        $company  = auth()->user()->company;

        if ($template->header_format !== 'TEXT' && !$template->header_handle) {
            return response()->json(['message' => "Upload a sample {$template->header_format} before submitting."], 422);
        }

        $this->submitToMeta($template, $company, $this->mergedPayloadForSubmit($template), isUpdate: false);

        return response()->json(['template' => $template->fresh()]);
    }

    // Rebuild the array shape submitToMeta()/buildComponents() expect, from the persisted model
    private function mergedPayloadForSubmit(WaTemplate $template): array
    {
        return [
            'name'           => $template->name,
            'category'       => $template->category,
            'language'       => $template->language,
            'body'           => $template->body,
            'body_examples'  => $template->body_examples,
            'header_format'  => $template->header_format,
            'header'         => $template->header,
            'header_example' => $template->header_example,
            'header_handle'  => $template->header_handle,
            'footer'         => $template->footer,
            'buttons'        => $template->buttons,
        ];
    }

    // ── Header media ───────────────────────────────────────────────────
    public function uploadHeaderMedia(int $id, Request $request): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);
        $company  = auth()->user()->company;

        if (!in_array($template->header_format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            return response()->json(['message' => 'This template\'s header format does not accept media.'], 422);
        }

        $meta = $this->uploadMediaToMeta($request, $company, 'template-headers');
        if (isset($meta['error'])) {

            Log::error($meta);

            return response()->json(['message' => $meta['error']], 422);
        }

        if ($template->header_sample_path) {
            Storage::disk('public')->delete($template->header_sample_path);
        }

        $this->templates->attachHeaderMedia($id, $company->id, $meta['handle'], $meta['path'], $meta['url']);

        return response()->json([
            'header_handle'      => $meta['handle'],
            'header_sample_url'  => $meta['url'],
            'header_sample_path' => $meta['path'],
        ]);
    }

    public function deleteHeaderMedia(int $id): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);

        if ($template->header_sample_path) {
            Storage::disk('public')->delete($template->header_sample_path);
        }

        $this->templates->clearHeaderMedia($id, auth()->user()->company_id);

        return response()->json(['message' => 'Header media removed.']);
    }

    // ── Footer media — stored for reference only, never sent to Meta ──────
    // (Meta's Graph API does not support media on FOOTER components.)
    public function uploadFooterMedia(int $id, Request $request): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);
        $company  = auth()->user()->company;

        $meta = $this->uploadMediaToMeta($request, $company, 'template-footers');
        if (isset($meta['error'])) {
            return response()->json(['message' => $meta['error']], 422);
        }

        if ($template->footer_media_path) {
            Storage::disk('public')->delete($template->footer_media_path);
        }

        $this->templates->attachFooterMedia($id, $company->id, $meta['handle'], $meta['path'], $meta['url']);

        return response()->json([
            'footer_media_url'  => $meta['url'],
            'footer_media_path' => $meta['path'],
        ]);
    }

    public function deleteFooterMedia(int $id): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);

        if ($template->footer_media_path) {
            Storage::disk('public')->delete($template->footer_media_path);
        }

        $this->templates->clearFooterMedia($id, auth()->user()->company_id);

        return response()->json(['message' => 'Footer media removed.']);
    }

    // ── Per-button media — stored for reference only, never sent to Meta ──
    // (Meta's BUTTONS component is text/url/phone only — no media field exists there.)
    // $buttonId is the button's position (index) in the template's buttons array.
    public function uploadButtonMedia(int $id, int $buttonId, Request $request): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);
        $buttons  = $template->buttons ?? [];

        if (!isset($buttons[$buttonId])) {
            return response()->json(['message' => 'Button not found on this template.'], 404);
        }

        $company = auth()->user()->company;
        $meta = $this->uploadMediaToMeta($request, $company, 'template-buttons');
        if (isset($meta['error'])) {
            return response()->json(['message' => $meta['error']], 422);
        }

        if (!empty($buttons[$buttonId]['media_path'])) {
            Storage::disk('public')->delete($buttons[$buttonId]['media_path']);
        }

        $this->templates->attachButtonMedia($id, $company->id, $buttonId, $meta['handle'], $meta['path'], $meta['url']);

        return response()->json([
            'button_id'  => $buttonId,
            'media_url'  => $meta['url'],
            'media_path' => $meta['path'],
        ]);
    }

    public function deleteButtonMedia(int $id, int $buttonId): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id);
        $buttons  = $template->buttons ?? [];

        if (!isset($buttons[$buttonId])) {
            return response()->json(['message' => 'Button not found on this template.'], 404);
        }

        if (!empty($buttons[$buttonId]['media_path'])) {
            Storage::disk('public')->delete($buttons[$buttonId]['media_path']);
        }

        $this->templates->clearButtonMedia($id, auth()->user()->company_id, $buttonId);

        return response()->json(['message' => 'Button media removed.']);
    }

    // ── Shared upload-to-Meta helper (resumable upload session → PUT bytes) ─
    private function uploadMediaToMeta(Request $request, $company, string $storageFolder): array
    {
        $request->validate(['file' => ['required', 'file', 'max:16384']]); // 16MB safety cap

        if (!$company->decrypt_wa_access_token || !$company->wa_business_id) {
            return ['error' => 'WhatsApp app credentials not configured.'];
        }

        $file = $request->file('file');
        $path = $file->store($storageFolder, 'public');
        $publicUrl = Storage::disk('public')->url($path);

        $session = Http::withToken($company->decrypt_wa_access_token)
            ->post("https://graph.facebook.com/v20.0/{$company->wa_phone_id}/uploads", [
                'file_length' => $file->getSize(),
                'file_type'   => $file->getMimeType(),
            ]);

        if ($session->failed()) {
            Storage::disk('public')->delete($path);
            return ['error' => $session->json('error.message') ?? 'Failed to start upload session.'];
        }

        $uploadSessionId = $session->json('id'); // format: "upload:XYZ"

        $upload = Http::withHeaders([
            'Authorization' => 'OAuth ' . $company->decrypt_wa_access_token,
            'file_offset'   => '0',
        ])
            ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
            ->post("https://graph.facebook.com/v20.0/{$uploadSessionId}");

        if ($upload->failed()) {
            Storage::disk('public')->delete($path);
            return ['error' => $upload->json('error.message') ?? 'Failed to upload sample media.'];
        }

        return ['handle' => $upload->json('h'), 'path' => $path, 'url' => $publicUrl];
    }

    // ── Validation shared by store/update ──────────────────────────────
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $d = $request->validate([
            'name'            => [$isUpdate ? 'sometimes' : 'required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:100'],
            'category'        => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'language'        => ['required', 'string', 'max:10'],
            'body'            => ['required', 'string', 'max:1024'],
            'body_examples'   => ['nullable', 'array'],
            'body_examples.*' => ['string', 'max:255'],
            'header_format'   => ['nullable', 'in:TEXT,IMAGE,VIDEO,DOCUMENT'],
            'header'          => ['nullable', 'string', 'max:60'],
            'header_example'  => ['nullable', 'string', 'max:255'],
            'footer'          => ['nullable', 'string', 'max:60'],
            'buttons'         => ['nullable', 'array', 'max:3'],
            'buttons.*.type'  => ['required_with:buttons', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
            'buttons.*.text'  => ['required_with:buttons', 'string', 'max:25'],
            'buttons.*.url'   => ['nullable', 'url'],
        ]);

        $d['header_format'] = $d['header_format'] ?? 'TEXT';

        if ($d['header_format'] !== 'TEXT') {
            $d['header'] = null; // media headers don't carry inline text
        }

        return $d;
    }

    // ── Push a create or update to Meta, mirror status/errors back locally ─
    private function submitToMeta(WaTemplate $template, $company, array $d, bool $isUpdate = false): void
    {
        if (!$company->decrypt_wa_access_token || !$company->wa_business_id) {
            return;
        }

        $components = $this->buildComponents($d);

        if ($isUpdate && $template->wa_template_id) {
            $response = Http::withToken($company->decrypt_wa_access_token)
                ->post("https://graph.facebook.com/v25.0/{$template->wa_template_id}", [
                    'category'   => $d['category'],
                    'components' => $components,
                ]);
        } else {
            $response = Http::withToken($company->decrypt_wa_access_token)
                ->post("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
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

    // ── Build Meta component array — only HEADER may carry media; ─────────
    // FOOTER and BUTTONS are always text-only per Meta's spec, so any
    // footer/button media on our side is deliberately excluded here.
    private function buildComponents(array $d): array
    {
        $components = [];
        $headerFormat = $d['header_format'] ?? 'TEXT';

        if ($headerFormat === 'TEXT' && !empty($d['header'])) {
            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $d['header']];
            if (!empty($d['header_example'])) {
                $header['example'] = ['header_text' => [$d['header_example']]];
            }
            $components[] = $header;
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT']) && !empty($d['header_handle'])) {
            $components[] = [
                'type'    => 'HEADER',
                'format'  => $headerFormat,
                'example' => ['header_handle' => [$d['header_handle']]],
            ];
        }

        $body = ['type' => 'BODY', 'text' => $d['body']];
        if (!empty($d['body_examples'])) {
            $body['example'] = ['body_text' => [array_values($d['body_examples'])]];
        }
        $components[] = $body;

        if (!empty($d['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $d['footer']]; // text only, by design
        }

        if (!empty($d['buttons'])) {
            $buttons = collect($d['buttons'])->map(fn($b) => array_filter([
                'type' => $b['type'] ?? 'QUICK_REPLY',
                'text' => $b['text'],
                'url'  => $b['type'] === 'URL' ? ($b['url'] ?? null) : null,
            ], fn($v) => !is_null($v)))->toArray(); // media fields intentionally dropped here

            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
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

        if ($template->wa_template_id && $company->decrypt_wa_access_token && $company->wa_business_id) {
            Http::withToken($company->decrypt_wa_access_token)
                ->delete("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
                    'hsm_id' => $template->wa_template_id,
                    'name'   => $template->name,
                ]);
        }

        foreach (array_filter([$template->header_sample_path, $template->footer_media_path]) as $path) {
            Storage::disk('public')->delete($path);
        }
        foreach (($template->buttons ?? []) as $btn) {
            if (!empty($btn['media_path'])) Storage::disk('public')->delete($btn['media_path']);
        }

        $template->delete();
        return response()->json(['message' => 'Template deleted from platform and Meta.']);
    }

    // ── Bulk sync all templates from Meta (the "🔄 Sync from Meta" button) ─
    public function syncFromMeta(): JsonResponse
    {
        $company = auth()->user()->company;

        if (!$company->decrypt_wa_access_token || !$company->wa_business_id) {
            return response()->json(['message' => 'WhatsApp credentials not set.'], 422);
        }

        $response = Http::withToken($company->decrypt_wa_access_token)
            ->get("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
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

            // skip malformed entries defensively instead of crashing the whole sync
            if (empty($meta['name']) || empty($meta['id'])) {
                continue;
            }

            WaTemplate::updateOrCreate(
                ['company_id' => $company->id, 'name' => $meta['name']],
                [
                    'wa_template_id'   => $meta['id'],
                    'category'         => $meta['category'] ?? 'UTILITY',
                    'language'         => $meta['language'] ?? 'en',
                    'body'             => $bodyComponent['text'] ?? '',
                    'body_examples'    => $bodyComponent['example']['body_text'][0] ?? null,
                    'header_format'    => $headerComponent['format'] ?? 'TEXT',
                    'header'           => ($headerComponent['format'] ?? 'TEXT') === 'TEXT' ? ($headerComponent['text'] ?? null) : null,
                    'status'           => strtolower($meta['status'] ?? 'pending'),
                    'rejection_reason' => $meta['rejected_reason'] ?? null,
                ]
            );
            $updated++;
        }
        // foreach ($response->json('data', []) as $meta) {
        //     $components = collect($meta['components'] ?? []);
        //     $bodyComponent   = $components->firstWhere('type', 'BODY');
        //     $headerComponent = $components->firstWhere('type', 'HEADER');

        //     WaTemplate::updateOrCreate(
        //         ['company_id' => $company->id, 'name' => $meta['name']],
        //         [
        //             'wa_template_id'   => $meta['id'],
        //             'category'         => $meta['category'],
        //             'language'         => $meta['language'],
        //             'body'             => $bodyComponent['text'] ?? '',
        //             'body_examples'    => $bodyComponent['example']['body_text'][0] ?? null,
        //             'header_format'    => $headerComponent['format'] ?? 'TEXT',
        //             'header'           => ($headerComponent['format'] ?? 'TEXT') === 'TEXT' ? ($headerComponent['text'] ?? null) : null,
        //             'status'           => strtolower($meta['status']),
        //             'rejection_reason' => $meta['rejected_reason'] ?? null,
        //         ]
        //     );
        //     $updated++;
        // }

        return response()->json(['message' => "Synced {$updated} templates from Meta."]);
    }

    // ── Per-template single status refresh from Meta ───────────────────────
    public function syncSingle(int $id): JsonResponse
    {
        $template = $this->templates->syncSingleFromMeta($id, auth()->user()->company_id);
        return response()->json(['message' => 'Synced from Meta.', 'template' => $template]);
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
