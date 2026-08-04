<?php
namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Services\TemplateService;
use App\Http\Controllers\Controller;
use App\Models\WaTemplate;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TemplateController extends Controller
{
    // Meta only allows editing a template that is still a local draft or that Meta
    // itself rejected — approved/pending templates are locked on Meta's side and any
    // edit/resubmit attempt against them fails with error_subcode 2388003. We mirror
    // that rule here so the user gets an immediate, clear message instead of a 400
    // from Meta after a round trip.
    private const EDITABLE_STATUSES = ['draft', 'rejected', 'error'];
    private const LOCKED_STATUSES   = ['approved', 'pending', 'pending_deletion', 'disabled'];

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
        $template = $this->templates->show($id, auth()->user()->company_id);

        if (in_array($template->status, self::LOCKED_STATUSES, true)) {
            return response()->json([
                'message' => 'Approved or pending templates cannot be edited on Meta. Duplicate this template to create a new version instead.',
            ], 422);
        }

        $d = $this->validated($request, isUpdate: true);

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

        if (!in_array($template->status, self::EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Only draft or rejected templates can be submitted. This template is already \"{$template->status}\" on Meta.",
            ], 422);
        }

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
            $this->markMediaUploadResult($template, $meta['error']);
            return response()->json(['message' => $meta['error']], 422);
        }
        $this->markMediaUploadResult($template, null);

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
            $this->markMediaUploadResult($template, $meta['error']);
            return response()->json(['message' => $meta['error']], 422);
        }
        $this->markMediaUploadResult($template, null);

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
            $this->markMediaUploadResult($template, $meta['error']);
            return response()->json(['message' => $meta['error']], 422);
        }
        $this->markMediaUploadResult($template, null);

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

    // ── Persist a media-upload outcome onto the template so it survives ────
    // a page refresh, instead of only existing as a one-time 422 toast.
    // Clears a stale error once a later upload on the same template succeeds.
    private function markMediaUploadResult(WaTemplate $template, ?string $error): void
    {
        if ($error) {
            $template->update([
                'status'           => 'error',
                'rejection_reason' => $error,
            ]);
        } elseif ($template->status === 'error') {
            $template->update([
                'status'           => 'draft',
                'rejection_reason' => null,
            ]);
        }
    }

    // ── Duplicate an existing template as a new draft ──────────────────────
    // Copies all content fields so the user only has to pick a new unique name
    // and, optionally, swap out media/text before submitting — instead of
    // retyping the whole template from scratch. Physically copies the sample
    // media file too, so the duplicate doesn't break if the original is later
    // edited or deleted.
    public function duplicate(int $id): JsonResponse
    {
        $company = auth()->user()->company;
        $source  = $this->templates->show($id, $company->id);

        $copiedHeaderPath = null;
        if ($source->header_sample_path && Storage::disk('public')->exists($source->header_sample_path)) {
            $ext = pathinfo($source->header_sample_path, PATHINFO_EXTENSION);
            $copiedHeaderPath = 'template-headers/' . uniqid('dup_') . ($ext ? ".{$ext}" : '');
            Storage::disk('public')->copy($source->header_sample_path, $copiedHeaderPath);
        }

        $duplicate = WaTemplate::create([
            'company_id'         => $company->id,
            // Left blank on purpose — template names must be unique, so the user
            // picks a new one in the modal rather than us guessing one for them.
            'name'               => $source->name . '_copy_' . substr(uniqid(), -4),
            'category'           => $source->category,
            'language'           => $source->language,
            'body'               => $source->body,
            'body_examples'      => $source->body_examples,
            'header_format'      => $source->header_format,
            'header'             => $source->header,
            'header_example'     => $source->header_example,
            // The Meta upload handle stays valid well past a single use, so it's safe to
            // reuse directly rather than forcing a re-upload — as long as we keep our own
            // copy of the file around locally for the preview to keep working.
            'header_handle'      => $copiedHeaderPath ? $source->header_handle : null,
            'header_sample_path' => $copiedHeaderPath,
            'header_sample_url'  => $copiedHeaderPath ? Storage::disk('public')->url($copiedHeaderPath) : null,
            'footer'             => $source->footer,
            // Per-button media is intentionally not copied — it's reference-only on our
            // side and cheap for the user to re-attach if they still want it.
            'buttons'            => collect($source->buttons ?? [])
                ->map(fn ($b) => collect($b)->except(['media_path', 'media_url'])->toArray())
                ->toArray(),
            'status'             => 'draft',
            'wa_template_id'     => null,
            'rejection_reason'   => null,
        ]);

        Log::info('[template-duplicate] created draft copy', [
            'source_id'      => $source->id,
            'duplicate_id'   => $duplicate->id,
        ]);

        return response()->json(['template' => $duplicate->fresh()], 201);
    }

    // ── Shared upload-to-Meta helper (resumable upload session → PUT bytes) ─
    // Every stage logs so a failure can be pinpointed to a single line in the logs.
    private function uploadMediaToMeta(Request $request, $company, string $storageFolder): array
    {
        Log::info('[media-upload] step 1/5: validating request', [
            'company_id' => $company->id,
            'folder'     => $storageFolder,
        ]);

        $request->validate(['file' => ['required', 'file', 'max:16384']]); // 16MB safety cap

        if (!$company->decrypt_wa_access_token || !$company->meta_app_id) {
            Log::error('[media-upload] step 1/5 FAILED: missing WhatsApp credentials', [
                'company_id'   => $company->id,
                'has_token'    => (bool) $company->decrypt_wa_access_token,
                'has_app_id'   => (bool) $company->meta_app_id,
            ]);
            return ['error' => 'WhatsApp app Id and credentials not configured.'];
        }

        $file = $request->file('file');
        Log::info('[media-upload] step 2/5: storing file locally', [
            'company_id' => $company->id,
            'filename'   => $file->getClientOriginalName(),
            'size'       => $file->getSize(),
            'mime'       => $file->getMimeType(),
        ]);

        $path = $file->store($storageFolder, 'public');
        if (!$path) {
            Log::error('[media-upload] step 2/5 FAILED: local disk store returned no path', ['company_id' => $company->id]);
            return ['error' => 'Failed to save the file locally.'];
        }
        $publicUrl = Storage::disk('public')->url($path);
        Log::info('[media-upload] step 2/5 OK: file stored', ['company_id' => $company->id, 'path' => $path]);

        Log::info('[media-upload] step 3/5: requesting Meta upload session', [
            'company_id' => $company->id,
            'app_id'     => $company->meta_app_id,
        ]);

        try {
            $session = Http::withToken($company->decrypt_wa_access_token)
                ->timeout(15)
                ->post("https://graph.facebook.com/v21.0/{$company->meta_app_id}/uploads", [
                    'file_length' => $file->getSize(),
                    'file_type'   => $file->getMimeType(),
                ]);
        } catch (ConnectionException $e) {
            Storage::disk('public')->delete($path);
            Log::error('[media-upload] step 3/5 FAILED: connection timeout starting session', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
            return ['error' => 'Timed out starting upload session with Meta. Please try again.'];
        }

        if ($session->failed()) {
            Storage::disk('public')->delete($path);
            Log::error('[media-upload] step 3/5 FAILED: Meta rejected session start', [
                'company_id' => $company->id,
                'http_code'  => $session->status(),
                'body'       => $session->json(),
            ]);
            return ['error' => $session->json('error.message') ?? 'Failed to start upload session.'];
        }

        $uploadSessionId = $session->json('id'); // format: "upload:XYZ"
        Log::info('[media-upload] step 3/5 OK: session created', [
            'company_id' => $company->id,
            'session_id' => $uploadSessionId,
        ]);

        Log::info('[media-upload] step 4/5: uploading file bytes to Meta', [
            'company_id' => $company->id,
            'session_id' => $uploadSessionId,
        ]);

        try {
            $upload = Http::withHeaders([
                'Authorization' => 'OAuth ' . $company->decrypt_wa_access_token,
                'file_offset'   => '0',
            ])
                ->timeout(60) // larger files need more room than the session call
                ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
                ->post("https://graph.facebook.com/v21.0/{$uploadSessionId}");
        } catch (ConnectionException $e) {
            Storage::disk('public')->delete($path);
            Log::error('[media-upload] step 4/5 FAILED: connection timeout uploading bytes', [
                'company_id' => $company->id,
                'session_id' => $uploadSessionId,
                'error'      => $e->getMessage(),
            ]);
            return ['error' => 'Timed out uploading media to Meta. Please try again.'];
        }

        if ($upload->failed()) {
            Storage::disk('public')->delete($path);
            Log::error('[media-upload] step 4/5 FAILED: Meta rejected byte upload', [
                'company_id' => $company->id,
                'session_id' => $uploadSessionId,
                'http_code'  => $upload->status(),
                'body'       => $upload->json(),
            ]);
            return ['error' => $upload->json('error.message') ?? 'Failed to upload sample media.'];
        }

        $handle = $upload->json('h');
        if (!$handle) {
            Storage::disk('public')->delete($path);
            Log::error('[media-upload] step 5/5 FAILED: Meta returned success but no handle ("h")', [
                'company_id' => $company->id,
                'session_id' => $uploadSessionId,
                'body'       => $upload->json(),
            ]);
            return ['error' => 'Meta did not return a media handle. Please try again.'];
        }

        Log::info('[media-upload] step 5/5 OK: upload complete', [
            'company_id' => $company->id,
            'handle'     => $handle,
            'path'       => $path,
        ]);

        return ['handle' => $handle, 'path' => $path, 'url' => $publicUrl];
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
            'buttons.*.url'   => ['nullable', 'required_if:buttons.*.type,URL', 'url'],
            'buttons.*.phone_number' => ['nullable', 'required_if:buttons.*.type,PHONE_NUMBER', 'string', 'max:20'],
        ]);

        $d['header_format'] = $d['header_format'] ?? 'TEXT';

        if ($d['header_format'] !== 'TEXT') {
            $d['header'] = null; // media headers don't carry inline text
        }

        return $d;
    }

    // ── Push a create or update to Meta, mirror status/errors back locally ─
    // Every stage logs so a failed submission can be pinpointed to a single line in the logs.
    private function submitToMeta(WaTemplate $template, $company, array $d, bool $isUpdate = false): void
    {
        Log::info('[template-submit] step 1/4: checking credentials', [
            'template_id' => $template->id,
            'company_id'  => $company->id,
            'is_update'   => $isUpdate,
        ]);

        if (!$company->decrypt_wa_access_token || !$company->wa_business_id) {
            Log::error('[template-submit] step 1/4 FAILED: missing WhatsApp credentials, submit skipped silently', [
                'template_id'      => $template->id,
                'company_id'       => $company->id,
                'has_token'        => (bool) $company->decrypt_wa_access_token,
                'has_business_id'  => (bool) $company->wa_business_id,
            ]);
            return;
        }

        Log::info('[template-submit] step 2/4: building components payload', [
            'template_id' => $template->id,
        ]);
        $components = $this->buildComponents($d);
        Log::info('[template-submit] step 2/4 OK', [
            'template_id' => $template->id,
            'components'  => $components,
        ]);

        $endpoint = ($isUpdate && $template->wa_template_id)
            ? "https://graph.facebook.com/v25.0/{$template->wa_template_id}"
            : "https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates";

        Log::info('[template-submit] step 3/4: calling Meta', [
            'template_id' => $template->id,
            'endpoint'    => $endpoint,
            'mode'        => ($isUpdate && $template->wa_template_id) ? 'update' : 'create',
        ]);

        try {
            if ($isUpdate && $template->wa_template_id) {
                $response = Http::withToken($company->decrypt_wa_access_token)
                    ->timeout(20)
                    ->post($endpoint, [
                        'category'   => $d['category'],
                        'components' => $components,
                    ]);
            } else {
                $response = Http::withToken($company->decrypt_wa_access_token)
                    ->timeout(20)
                    ->post($endpoint, [
                        'name'       => $d['name'],
                        'category'   => $d['category'],
                        'language'   => $d['language'],
                        'components' => $components,
                    ]);
            }
        } catch (ConnectionException $e) {
            Log::error('[template-submit] step 3/4 FAILED: connection timeout calling Meta', [
                'template_id' => $template->id,
                'endpoint'    => $endpoint,
                'error'       => $e->getMessage(),
            ]);
            $template->update([
                'status'           => 'error',
                'rejection_reason' => 'Timed out contacting Meta. Please try submitting again.',
            ]);
            return;
        }

        Log::info('[template-submit] step 3/4 OK: Meta responded', [
            'template_id' => $template->id,
            'http_code'   => $response->status(),
        ]);

        Log::info('[template-submit] step 4/4: updating local template row', [
            'template_id' => $template->id,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $template->update([
                'wa_template_id'   => $data['id'] ?? $template->wa_template_id,
                'status'           => strtolower($data['status'] ?? 'pending'),
                'rejection_reason' => null,
            ]);
            Log::info('[template-submit] step 4/4 OK: template marked as submitted', [
                'template_id'    => $template->id,
                'wa_template_id' => $data['id'] ?? $template->wa_template_id,
                'status'         => strtolower($data['status'] ?? 'pending'),
            ]);
        } else {
            $subcode = $response->json('error.error_subcode');
            $reason  = $response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta API error';

            // subcode 2388024: Meta already has a template with this name+language, almost
            // always because an earlier "create" attempt actually succeeded on Meta's side
            // before our local row caught up (e.g. we timed out reading the response, or the
            // button-validation bug from before meant we never advanced past isUpdate=false).
            // Rather than leave the row endlessly retrying a doomed create, look the real
            // template up and link it so status/id are accurate going forward.
            if ($subcode === 2388024 && !$isUpdate) {
                Log::warning('[template-submit] step 4/4: content already exists on Meta, reconciling', [
                    'template_id' => $template->id,
                    'name'        => $d['name'],
                ]);
                $this->reconcileExistingFromMeta($template, $company, $d['name']);
                return;
            }

            Log::error('[template-submit] step 4/4 FAILED: Meta rejected the template', [
                'template_id' => $template->id,
                'http_code'   => $response->status(),
                'body'        => $response->json(),
                'reason'      => $reason,
            ]);
            $template->update([
                'status'           => 'error',
                'rejection_reason' => $reason,
            ]);
        }
    }

    // ── Recover from a "content already exists" create failure by pulling the
    // real template (id/status) down from Meta and linking it to our local row,
    // instead of leaving the row stuck retrying a create that can never succeed.
    private function reconcileExistingFromMeta(WaTemplate $template, $company, string $name): void
    {
        try {
            $lookup = Http::withToken($company->decrypt_wa_access_token)
                ->timeout(15)
                ->get("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
                    'fields' => 'id,name,language,status,rejected_reason',
                    'name'   => $name,
                ]);
        } catch (ConnectionException $e) {
            Log::error('[template-reconcile] FAILED: timeout looking up existing template', [
                'template_id' => $template->id,
                'name'        => $name,
                'error'       => $e->getMessage(),
            ]);
            $template->update([
                'status'           => 'error',
                'rejection_reason' => 'A template with this name already exists on Meta. Try "Sync from Meta" to link it, or rename this one.',
            ]);
            return;
        }

        $match = collect($lookup->json('data', []))->first();

        if ($match) {
            $template->update([
                'wa_template_id'   => $match['id'],
                'status'           => strtolower($match['status'] ?? 'pending'),
                'rejection_reason' => $match['rejected_reason'] ?? null,
            ]);
            Log::info('[template-reconcile] OK: linked existing Meta template', [
                'template_id'    => $template->id,
                'wa_template_id' => $match['id'],
                'status'         => strtolower($match['status'] ?? 'pending'),
            ]);
        } else {
            Log::error('[template-reconcile] FAILED: no matching template found on Meta', [
                'template_id' => $template->id,
                'name'        => $name,
            ]);
            $template->update([
                'status'           => 'error',
                'rejection_reason' => 'A template with this name/language already exists on Meta but could not be found automatically. Try "Sync from Meta" or rename this template.',
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
            $buttons = collect($d['buttons'])->map(function ($b) {
                $isPhone = ($b['type'] ?? 'QUICK_REPLY') === 'PHONE_NUMBER';

                // Frontend may send the number under a dedicated `phone_number` field, or
                // (legacy behavior) under `text` itself — support both so nothing breaks.
                $phoneNumber = $b['phone_number'] ?? ($isPhone ? ($b['text'] ?? null) : null);

                return array_filter([
                    'type'         => $b['type'] ?? 'QUICK_REPLY',
                    'text'         => $isPhone ? ($b['label'] ?? $b['text'] ?? 'Call') : $b['text'],
                    'url'          => $b['type'] === 'URL' ? ($b['url'] ?? null) : null,
                    'phone_number' => $isPhone ? $phoneNumber : null,
                ], fn($v) => !is_null($v));
            })->toArray(); // media fields intentionally dropped here

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
            try {
                Http::withToken($company->decrypt_wa_access_token)
                    ->timeout(20)
                    ->delete("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
                        'hsm_id' => $template->wa_template_id,
                        'name'   => $template->name,
                    ]);
            } catch (ConnectionException $e) {
                Log::warning('Meta template delete timed out.', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                // proceed with local delete anyway; the Meta side can be cleaned up via sync later
            }
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

        try {
            $response = Http::withToken($company->decrypt_wa_access_token)
                ->timeout(20)
                ->get("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
                    'fields' => 'id,name,category,language,status,rejected_reason,components',
                    'limit'  => 100,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Meta sync timed out.', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Timed out contacting Meta. Please try again.'], 422);
        }

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
//     public function __construct(private TemplateService $templates) {}

//     // ── List / show ────────────────────────────────────────────────────
//     public function index(Request $request): JsonResponse
//     {
//         $paginated = $this->templates->list(auth()->user()->company_id, $request->all());
//         return response()->json([
//             'templates' => $paginated->items(),
//             'total'     => $paginated->total(),
//         ]);
//     }

//     public function show(int $id): JsonResponse
//     {
//         return response()->json(['template' => $this->templates->show($id, auth()->user()->company_id)]);
//     }

//     // ── Create as DRAFT — does NOT push to Meta yet ───────────────────────
//     // Media (header/footer/button) is uploaded against this id afterwards,
//     // then the frontend calls /submit to actually push to Meta once ready.
//     public function store(Request $request): JsonResponse
//     {
//         $d = $this->validated($request);

//         $template = $this->templates->create(auth()->user()->company_id, [
//             ...$d,
//             'status' => 'draft',
//         ]);

//         return response()->json(['template' => $template->fresh()], 201);
//     }

//     // ── Update a draft or resubmit an existing template ───────────────────
//     public function update(int $id, Request $request): JsonResponse
//     {
//         $d = $this->validated($request, isUpdate: true);

//         $template = $this->templates->show($id, auth()->user()->company_id);
//         $wasSubmitted = $template->status !== 'draft';

//         $template = $this->templates->update($id, auth()->user()->company_id, [
//             ...$d,
//             // Only force back to draft if it hadn't been submitted yet; if it was
//             // already live on Meta, editing should re-submit rather than orphan it as a draft
//             'status' => $wasSubmitted ? 'pending' : 'draft',
//         ]);

//         if ($wasSubmitted) {
//             $company = auth()->user()->company;
//             $this->submitToMeta($template, $company, $this->mergedPayloadForSubmit($template), isUpdate: true);
//         }

//         return response()->json(['template' => $template->fresh()]);
//     }

//     // ── Push a draft to Meta for the first time ───────────────────────────
//     public function submit(int $id): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);
//         $company  = auth()->user()->company;

//         if ($template->header_format !== 'TEXT' && !$template->header_handle) {
//             return response()->json(['message' => "Upload a sample {$template->header_format} before submitting."], 422);
//         }

//         $this->submitToMeta($template, $company, $this->mergedPayloadForSubmit($template), isUpdate: false);

//         return response()->json(['template' => $template->fresh()]);
//     }

//     // Rebuild the array shape submitToMeta()/buildComponents() expect, from the persisted model
//     private function mergedPayloadForSubmit(WaTemplate $template): array
//     {
//         return [
//             'name'           => $template->name,
//             'category'       => $template->category,
//             'language'       => $template->language,
//             'body'           => $template->body,
//             'body_examples'  => $template->body_examples,
//             'header_format'  => $template->header_format,
//             'header'         => $template->header,
//             'header_example' => $template->header_example,
//             'header_handle'  => $template->header_handle,
//             'footer'         => $template->footer,
//             'buttons'        => $template->buttons,
//         ];
//     }

//     // ── Header media ───────────────────────────────────────────────────
//     public function uploadHeaderMedia(int $id, Request $request): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);
//         $company  = auth()->user()->company;

//         if (!in_array($template->header_format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
//             return response()->json(['message' => 'This template\'s header format does not accept media.'], 422);
//         }

//         $meta = $this->uploadMediaToMeta($request, $company, 'template-headers');
//         if (isset($meta['error'])) {
//             return response()->json(['message' => $meta['error']], 422);
//         }

//         if ($template->header_sample_path) {
//             Storage::disk('public')->delete($template->header_sample_path);
//         }

//         $this->templates->attachHeaderMedia($id, $company->id, $meta['handle'], $meta['path'], $meta['url']);

//         return response()->json([
//             'header_handle'      => $meta['handle'],
//             'header_sample_url'  => $meta['url'],
//             'header_sample_path' => $meta['path'],
//         ]);
//     }

//     public function deleteHeaderMedia(int $id): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);

//         if ($template->header_sample_path) {
//             Storage::disk('public')->delete($template->header_sample_path);
//         }

//         $this->templates->clearHeaderMedia($id, auth()->user()->company_id);

//         return response()->json(['message' => 'Header media removed.']);
//     }

//     // ── Footer media — stored for reference only, never sent to Meta ──────
//     // (Meta's Graph API does not support media on FOOTER components.)
//     public function uploadFooterMedia(int $id, Request $request): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);
//         $company  = auth()->user()->company;

//         $meta = $this->uploadMediaToMeta($request, $company, 'template-footers');
//         if (isset($meta['error'])) {
//             return response()->json(['message' => $meta['error']], 422);
//         }

//         if ($template->footer_media_path) {
//             Storage::disk('public')->delete($template->footer_media_path);
//         }

//         $this->templates->attachFooterMedia($id, $company->id, $meta['handle'], $meta['path'], $meta['url']);

//         return response()->json([
//             'footer_media_url'  => $meta['url'],
//             'footer_media_path' => $meta['path'],
//         ]);
//     }

//     public function deleteFooterMedia(int $id): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);

//         if ($template->footer_media_path) {
//             Storage::disk('public')->delete($template->footer_media_path);
//         }

//         $this->templates->clearFooterMedia($id, auth()->user()->company_id);

//         return response()->json(['message' => 'Footer media removed.']);
//     }

//     // ── Per-button media — stored for reference only, never sent to Meta ──
//     // (Meta's BUTTONS component is text/url/phone only — no media field exists there.)
//     // $buttonId is the button's position (index) in the template's buttons array.
//     public function uploadButtonMedia(int $id, int $buttonId, Request $request): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);
//         $buttons  = $template->buttons ?? [];

//         if (!isset($buttons[$buttonId])) {
//             return response()->json(['message' => 'Button not found on this template.'], 404);
//         }

//         $company = auth()->user()->company;
//         $meta = $this->uploadMediaToMeta($request, $company, 'template-buttons');
//         if (isset($meta['error'])) {
//             return response()->json(['message' => $meta['error']], 422);
//         }

//         if (!empty($buttons[$buttonId]['media_path'])) {
//             Storage::disk('public')->delete($buttons[$buttonId]['media_path']);
//         }

//         $this->templates->attachButtonMedia($id, $company->id, $buttonId, $meta['handle'], $meta['path'], $meta['url']);

//         return response()->json([
//             'button_id'  => $buttonId,
//             'media_url'  => $meta['url'],
//             'media_path' => $meta['path'],
//         ]);
//     }

//     public function deleteButtonMedia(int $id, int $buttonId): JsonResponse
//     {
//         $template = $this->templates->show($id, auth()->user()->company_id);
//         $buttons  = $template->buttons ?? [];

//         if (!isset($buttons[$buttonId])) {
//             return response()->json(['message' => 'Button not found on this template.'], 404);
//         }

//         if (!empty($buttons[$buttonId]['media_path'])) {
//             Storage::disk('public')->delete($buttons[$buttonId]['media_path']);
//         }

//         $this->templates->clearButtonMedia($id, auth()->user()->company_id, $buttonId);

//         return response()->json(['message' => 'Button media removed.']);
//     }

//     // ── Shared upload-to-Meta helper (resumable upload session → PUT bytes) ─
//     private function uploadMediaToMeta(Request $request, $company, string $storageFolder): array
//     {
//         $request->validate(['file' => ['required', 'file', 'max:16384']]); // 16MB safety cap

//         if (!$company->decrypt_wa_access_token || !$company->meta_app_id) {
//             return ['error' => 'WhatsApp app Id and credentials not configured.'];
//         }

//         $file = $request->file('file');
//         $path = $file->store($storageFolder, 'public');
//         $publicUrl = Storage::disk('public')->url($path);

//         $session = Http::withToken($company->decrypt_wa_access_token)
//             ->post("https://graph.facebook.com/v21.0/{$company->meta_app_id}/uploads", [
//                 'file_length' => $file->getSize(),
//                 'file_type'   => $file->getMimeType(),
//             ]);

//         if ($session->failed()) {
//             Storage::disk('public')->delete($path);
//             return ['error' => $session->json('error.message') ?? 'Failed to start upload session.'];
//         }

//         $uploadSessionId = $session->json('id'); // format: "upload:XYZ"

//         $upload = Http::withHeaders([
//             'Authorization' => 'OAuth ' . $company->decrypt_wa_access_token,
//             'file_offset'   => '0',
//         ])
//             ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
//             ->post("https://graph.facebook.com/v21.0/{$uploadSessionId}");

//         if ($upload->failed()) {
//             Storage::disk('public')->delete($path);
//             return ['error' => $upload->json('error.message') ?? 'Failed to upload sample media.'];
//         }

//         return ['handle' => $upload->json('h'), 'path' => $path, 'url' => $publicUrl];
//     }

//     // ── Validation shared by store/update ──────────────────────────────
//     private function validated(Request $request, bool $isUpdate = false): array
//     {
//         $d = $request->validate([
//             'name'            => [$isUpdate ? 'sometimes' : 'required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:100'],
//             'category'        => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
//             'language'        => ['required', 'string', 'max:10'],
//             'body'            => ['required', 'string', 'max:1024'],
//             'body_examples'   => ['nullable', 'array'],
//             'body_examples.*' => ['string', 'max:255'],
//             'header_format'   => ['nullable', 'in:TEXT,IMAGE,VIDEO,DOCUMENT'],
//             'header'          => ['nullable', 'string', 'max:60'],
//             'header_example'  => ['nullable', 'string', 'max:255'],
//             'footer'          => ['nullable', 'string', 'max:60'],
//             'buttons'         => ['nullable', 'array', 'max:3'],
//             'buttons.*.type'  => ['required_with:buttons', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
//             'buttons.*.text'  => ['required_with:buttons', 'string', 'max:25'],
//             'buttons.*.url'   => ['nullable', 'url'],
//         ]);

//         $d['header_format'] = $d['header_format'] ?? 'TEXT';

//         if ($d['header_format'] !== 'TEXT') {
//             $d['header'] = null; // media headers don't carry inline text
//         }

//         return $d;
//     }

//     // ── Push a create or update to Meta, mirror status/errors back locally ─
//     private function submitToMeta(WaTemplate $template, $company, array $d, bool $isUpdate = false): void
//     {
//         if (!$company->decrypt_wa_access_token || !$company->wa_business_id) {
//             return;
//         }

//         $components = $this->buildComponents($d);

//         if ($isUpdate && $template->wa_template_id) {
//             $response = Http::withToken($company->decrypt_wa_access_token)
//                 ->post("https://graph.facebook.com/v25.0/{$template->wa_template_id}", [
//                     'category'   => $d['category'],
//                     'components' => $components,
//                 ]);
//         } else {
//             $response = Http::withToken($company->decrypt_wa_access_token)
//                 ->post("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
//                     'name'       => $d['name'],
//                     'category'   => $d['category'],
//                     'language'   => $d['language'],
//                     'components' => $components,
//                 ]);
//         }

//         if ($response->successful()) {
//             $data = $response->json();
//             $template->update([
//                 'wa_template_id'   => $data['id'] ?? $template->wa_template_id,
//                 'status'           => strtolower($data['status'] ?? 'pending'),
//                 'rejection_reason' => null,
//             ]);
//         } else {
//             $template->update([
//                 'status'           => 'error',
//                 'rejection_reason' => $response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta API error',
//             ]);
//         }
//     }

//     // ── Build Meta component array — only HEADER may carry media; ─────────
//     // FOOTER and BUTTONS are always text-only per Meta's spec, so any
//     // footer/button media on our side is deliberately excluded here.
//     private function buildComponents(array $d): array
//     {
//         $components = [];
//         $headerFormat = $d['header_format'] ?? 'TEXT';

//         if ($headerFormat === 'TEXT' && !empty($d['header'])) {
//             $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $d['header']];
//             if (!empty($d['header_example'])) {
//                 $header['example'] = ['header_text' => [$d['header_example']]];
//             }
//             $components[] = $header;
//         } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT']) && !empty($d['header_handle'])) {
//             $components[] = [
//                 'type'    => 'HEADER',
//                 'format'  => $headerFormat,
//                 'example' => ['header_handle' => [$d['header_handle']]],
//             ];
//         }

//         $body = ['type' => 'BODY', 'text' => $d['body']];
//         if (!empty($d['body_examples'])) {
//             $body['example'] = ['body_text' => [array_values($d['body_examples'])]];
//         }
//         $components[] = $body;

//         if (!empty($d['footer'])) {
//             $components[] = ['type' => 'FOOTER', 'text' => $d['footer']]; // text only, by design
//         }

//         if (!empty($d['buttons'])) {
//             $buttons = collect($d['buttons'])->map(fn($b) => array_filter([
//                 'type' => $b['type'] ?? 'QUICK_REPLY',
//                 'text' => $b['text'],
//                 'url'  => $b['type'] === 'URL' ? ($b['url'] ?? null) : null,
//             ], fn($v) => !is_null($v)))->toArray(); // media fields intentionally dropped here

//             $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
//         }

//         return $components;
//     }

//     // ── Delete template from platform + Meta ─────────────────────────────
//     public function destroy(int $id): JsonResponse
//     {
//         $template = WaTemplate::where('id', $id)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $company = auth()->user()->company;

//         if ($template->wa_template_id && $company->decrypt_wa_access_token && $company->wa_business_id) {
//             Http::withToken($company->decrypt_wa_access_token)
//                 ->delete("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
//                     'hsm_id' => $template->wa_template_id,
//                     'name'   => $template->name,
//                 ]);
//         }

//         foreach (array_filter([$template->header_sample_path, $template->footer_media_path]) as $path) {
//             Storage::disk('public')->delete($path);
//         }
//         foreach (($template->buttons ?? []) as $btn) {
//             if (!empty($btn['media_path'])) Storage::disk('public')->delete($btn['media_path']);
//         }

//         $template->delete();
//         return response()->json(['message' => 'Template deleted from platform and Meta.']);
//     }

//     // ── Bulk sync all templates from Meta (the "🔄 Sync from Meta" button) ─
//     public function syncFromMeta(): JsonResponse
//     {
//         $company = auth()->user()->company;

//         if (!$company->decrypt_wa_access_token || !$company->wa_business_id) {
//             return response()->json(['message' => 'WhatsApp credentials not set.'], 422);
//         }

//         $response = Http::withToken($company->decrypt_wa_access_token)
//             ->get("https://graph.facebook.com/v25.0/{$company->wa_business_id}/message_templates", [
//                 'fields' => 'id,name,category,language,status,rejected_reason,components',
//                 'limit'  => 100,
//             ]);

//         if ($response->failed()) {
//             return response()->json(['message' => $response->json('error.message')], 422);
//         }

//         $updated = 0;
//         foreach ($response->json('data', []) as $meta) {
//             $components = collect($meta['components'] ?? []);
//             $bodyComponent   = $components->firstWhere('type', 'BODY');
//             $headerComponent = $components->firstWhere('type', 'HEADER');

//             // skip malformed entries defensively instead of crashing the whole sync
//             if (empty($meta['name']) || empty($meta['id'])) {
//                 continue;
//             }

//             WaTemplate::updateOrCreate(
//                 ['company_id' => $company->id, 'name' => $meta['name']],
//                 [
//                     'wa_template_id'   => $meta['id'],
//                     'category'         => $meta['category'] ?? 'UTILITY',
//                     'language'         => $meta['language'] ?? 'en',
//                     'body'             => $bodyComponent['text'] ?? '',
//                     'body_examples'    => $bodyComponent['example']['body_text'][0] ?? null,
//                     'header_format'    => $headerComponent['format'] ?? 'TEXT',
//                     'header'           => ($headerComponent['format'] ?? 'TEXT') === 'TEXT' ? ($headerComponent['text'] ?? null) : null,
//                     'status'           => strtolower($meta['status'] ?? 'pending'),
//                     'rejection_reason' => $meta['rejected_reason'] ?? null,
//                 ]
//             );
//             $updated++;
//         }
//         // foreach ($response->json('data', []) as $meta) {
//         //     $components = collect($meta['components'] ?? []);
//         //     $bodyComponent   = $components->firstWhere('type', 'BODY');
//         //     $headerComponent = $components->firstWhere('type', 'HEADER');

//         //     WaTemplate::updateOrCreate(
//         //         ['company_id' => $company->id, 'name' => $meta['name']],
//         //         [
//         //             'wa_template_id'   => $meta['id'],
//         //             'category'         => $meta['category'],
//         //             'language'         => $meta['language'],
//         //             'body'             => $bodyComponent['text'] ?? '',
//         //             'body_examples'    => $bodyComponent['example']['body_text'][0] ?? null,
//         //             'header_format'    => $headerComponent['format'] ?? 'TEXT',
//         //             'header'           => ($headerComponent['format'] ?? 'TEXT') === 'TEXT' ? ($headerComponent['text'] ?? null) : null,
//         //             'status'           => strtolower($meta['status']),
//         //             'rejection_reason' => $meta['rejected_reason'] ?? null,
//         //         ]
//         //     );
//         //     $updated++;
//         // }

//         return response()->json(['message' => "Synced {$updated} templates from Meta."]);
//     }

//     // ── Per-template single status refresh from Meta ───────────────────────
//     public function syncSingle(int $id): JsonResponse
//     {
//         $template = $this->templates->syncSingleFromMeta($id, auth()->user()->company_id);
//         return response()->json(['message' => 'Synced from Meta.', 'template' => $template]);
//     }
// }
