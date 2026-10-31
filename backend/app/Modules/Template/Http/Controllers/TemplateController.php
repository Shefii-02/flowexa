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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Meta\MetaGraph;
use App\Support\Meta\MetaMediaUploader;

class TemplateController extends Controller
{
    // Mirrors Meta's own edit rules. Meta lets a template be edited while it is APPROVED, REJECTED or
    // PAUSED (an approved one only about once per 24h and 10 times per 30 days — Meta enforces that and
    // we surface its message). PENDING (under review), DISABLED and pending-deletion templates cannot be
    // edited at all. Name and language never change once a template exists on Meta.
    private const LOCKED_STATUSES      = ['pending', 'pending_deletion', 'disabled'];
    // First-time / retry submission (create or re-send) — approved templates are edited, not submitted.
    private const SUBMITTABLE_STATUSES = ['draft', 'rejected', 'error'];
    // Live on Meta: a failed edit must never flip these into "error" locally.
    private const LIVE_STATUSES        = ['approved', 'paused'];

    public function __construct(private TemplateService $templates) {}

    // Every single-template action goes through this so a user restricted to certain WhatsApp
    // numbers can't read, edit, submit or delete another number's template by guessing its id.
    private function findTemplate(int $id): WaTemplate
    {
        return $this->templates->show(
            $id,
            auth()->user()->company_id,
            auth()->user()->allowedAccountIds('phone_number')
        );
    }

    // ── List / show ────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $paginated = $this->templates->list(
            auth()->user()->company_id, $request->all(), auth()->user()->allowedAccountIds('phone_number')
        );
        return response()->json([
            'templates' => $paginated->items(),
            'total'     => $paginated->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $template = $this->templates->show($id, auth()->user()->company_id, auth()->user()->allowedAccountIds('phone_number'));
        return response()->json(['template' => $template]);
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
        $template = $this->findTemplate($id);

        if (in_array($template->status, self::LOCKED_STATUSES, true)) {
            return response()->json([
                'message' => $template->status === 'pending'
                    ? 'This template is under review at Meta and cannot be edited until the review finishes. Refresh its status, or Duplicate it to start a new version.'
                    : "A {$template->status} template cannot be edited on Meta. Duplicate it to create a new version instead.",
            ], 422);
        }

        $d = $this->validated($request, isUpdate: true);

        // Meta never renames a submitted template; changing the local name/language would leave our
        // row and Meta's out of step (and the next sync would create a duplicate row).
        if ($template->wa_template_id) {
            unset($d['name'], $d['language']);
        }

        $wasSubmitted = $template->status !== 'draft';
        $wasLive      = in_array($template->status, self::LIVE_STATUSES, true);
        $before       = $template->getAttributes(); // raw row, so a refused edit can be rolled back exactly

        // An edit of a template that already went to Meta is pushed straight away, so a media header
        // needs its upload handle NOW. Without it buildComponents() drops the HEADER entirely and Meta
        // receives (and queues for review) a text-only template. Refuse instead of submitting that.
        $headerFormat = $d['header_format'] ?? $template->header_format;
        if ($wasSubmitted && $headerFormat !== 'TEXT' && strtoupper((string) ($d['category'] ?? $template->category)) !== 'AUTHENTICATION'
            && !$template->header_handle) {
            if ($this->canReuploadHeader($template)) {
                if ($err = $this->reuploadHeaderSample($template, auth()->user()->company)) {
                    return response()->json(['message' => $err], 422);
                }
                $template = $template->fresh();
            } else {
                return response()->json([
                    'message' => "Upload a sample {$headerFormat} first — this template has no header media, and submitting without it would drop the header.",
                ], 422);
            }
        }

        $template = $this->templates->update($id, auth()->user()->company_id, [
            ...$d,
            // Only force back to draft if it hadn't been submitted yet; if it was
            // already live on Meta, editing should re-submit rather than orphan it as a draft
            'status' => $wasSubmitted ? 'pending' : 'draft',
        ]);

        if ($wasSubmitted) {
            $company = auth()->user()->company;
            if ($template->template_type === 'carousel' && ($err = $this->ensureCarouselHandles($template, $company))) {
                WaTemplate::where('id', $id)->update(\Illuminate\Support\Arr::except($before, ['id', 'created_at', 'updated_at']));
                return response()->json(['message' => $err], 422);
            }
            $template = $template->fresh();
            $this->submitToMeta($template, $company, $this->mergedPayloadForSubmit($template), isUpdate: true);

            if ($wasLive) {
                $after = $template->fresh();
                if ($after->status === 'error') {
                    // Meta refused the edit (rate limit, policy, ...). The template is still live there, so put
                    // our copy back exactly as it was and report Meta's reason — don't leave it in "error".
                    $reason = $after->rejection_reason ?: 'Meta refused this edit.';
                    WaTemplate::where('id', $id)->update(\Illuminate\Support\Arr::except($before, ['id', 'created_at', 'updated_at']));
                    return response()->json(['message' => $reason], 422);
                }
                // Meta decides the status after an edit (it may stay approved or go back to review) — read it back.
                try {
                    $this->templates->syncSingleFromMeta($id, auth()->user()->company_id);
                } catch (\Throwable $e) {
                    Log::warning('[template-update] post-edit status refresh failed', ['template_id' => $id, 'error' => $e->getMessage()]);
                }
            }
        }

        return response()->json(['template' => $template->fresh()]);
    }

    // ── Push a draft to Meta for the first time ───────────────────────────
    public function submit(int $id): JsonResponse
    {
        $template = $this->findTemplate($id);
        $company  = auth()->user()->company;

        if (!in_array($template->status, self::SUBMITTABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Only draft or rejected templates can be submitted. This template is already \"{$template->status}\" on Meta.",
            ], 422);
        }

        if ($template->header_format !== 'TEXT' && !$template->header_handle) {
            // A duplicated template keeps its sample file locally but not Meta's upload handle
            // (handles are short-lived) — upload that file again now rather than reuse a stale one.
            if (!$this->canReuploadHeader($template)) {
                return response()->json(['message' => "Upload a sample {$template->header_format} before submitting."], 422);
            }
            if ($err = $this->reuploadHeaderSample($template, $company)) {
                return response()->json(['message' => $err], 422);
            }
            $template = $template->fresh();
        }

        if ($template->template_type === 'carousel') {
            if ($err = $this->ensureCarouselHandles($template, $company)) {
                return response()->json(['message' => $err], 422);
            }
            $template = $template->fresh();
        }

        // A rejected/errored template that already exists on Meta must be EDITED there — a create
        // call would only hit "name already exists" (2388024) and leave it rejected forever.
        try {
            $this->submitToMeta(
                $template, $company, $this->mergedPayloadForSubmit($template),
                isUpdate: (bool) $template->wa_template_id
            );
        } catch (\Throwable $e) {
            // Whatever went wrong, record the exact cause in the log and on the template, and answer with a message
            // the form can show — instead of a bare 500 that tells nobody anything.
            Log::error('[template-submit] UNEXPECTED ERROR: ' . $e->getMessage(), [
                'template_id' => $template->id, 'company_id' => $company->id,
                'exception'   => get_class($e), 'where' => basename($e->getFile()) . ':' . $e->getLine(),
                'trace'       => collect(explode("\n", $e->getTraceAsString()))->take(8)->all(),
            ]);
            $template->update(['status' => 'error', 'rejection_reason' => 'Submit failed: ' . mb_substr($e->getMessage(), 0, 300)]);
            return response()->json([
                'message'  => 'Could not submit the template: ' . mb_substr($e->getMessage(), 0, 300),
                'template' => $template->fresh(),
            ], 500);
        }

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
            'template_type'  => $template->template_type,
            'carousel_cards' => $template->carousel_cards,
            'carousel_media' => $template->carousel_media,
            'carousel_media_format' => $template->carousel_media_format,
            'message_send_ttl_seconds' => $template->message_send_ttl_seconds,
            'auth_delivery_method'             => $template->auth_delivery_method,
            'auth_add_expiry'                  => $template->auth_add_expiry,
            'auth_code_expiration_minutes'      => $template->auth_code_expiration_minutes,
            'auth_add_security_recommendation' => $template->auth_add_security_recommendation,
            'auth_apps'                         => $template->auth_apps,
            'auth_zero_tap_terms_accepted'      => $template->auth_zero_tap_terms_accepted,
        ];
    }

    // ── Header media ───────────────────────────────────────────────────
    public function uploadHeaderMedia(int $id, Request $request): JsonResponse
    {
        $template = $this->findTemplate($id);
        $company  = auth()->user()->company;

        // The form may be switching the header type in the same save (the sample is uploaded BEFORE the
        // edit is stored, so the row can still hold the old type — e.g. TEXT after a sync from Meta of a
        // template that had lost its header). The caller states the type it intends; fall back to the row's.
        $headerFormat = strtoupper((string) $request->input('header_format', $template->header_format));
        if (!in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            return response()->json(['message' => 'Choose an Image, Video or Document header before uploading a sample.'], 422);
        }

        // Meta lets an approved/rejected/paused template's header be changed; a pending (or disabled) one
        // cannot be. A failed upload must never flip a live template into "error" (see markMediaUploadResult).
        if (in_array($template->status, self::LOCKED_STATUSES, true)) {
            return response()->json(['message' => 'This template is under review (or disabled) at Meta, so its header cannot be changed right now. Wait for the review to finish, or Duplicate it.'], 422);
        }

        // Either a freshly uploaded file, or the URL of a file already in this company's Media Library.
        $request->validate([
            'file'      => ['required_without:media_url', 'file', 'max:16384'], // 16MB safety cap
            'media_url' => ['required_without:file', 'string', 'max:2000'],
            'header_format' => ['nullable', 'in:IMAGE,VIDEO,DOCUMENT,image,video,document'],
        ]);

        $file = $request->file('file');
        if (!$file) {
            // Library files are looked up by (company, url) and read straight from our own disk — never
            // fetched from an arbitrary URL, so a template can't be used to make the server request other hosts.
            $item = \App\Modules\WaChat\Models\MediaLibrary::where('company_id', $company->id)
                ->where('url', $request->input('media_url'))->first();
            if (!$item || !Storage::disk($item->disk ?: 'public')->exists($item->path)) {
                return response()->json(['message' => 'That file was not found in your Media Library.'], 422);
            }
            $disk = Storage::disk($item->disk ?: 'public');
            $file = new \Illuminate\Http\UploadedFile($disk->path($item->path), $item->original_name ?: basename($item->path), $item->mime_type ?: $disk->mimeType($item->path), null, true);
            if ($file->getSize() > 16384 * 1024) {
                return response()->json(['message' => 'That file is larger than 16 MB.'], 422);
            }
        }

        if ($rule = \App\Support\Meta\MediaRules::forHeaderFormat($headerFormat)) {
            if ($err = \App\Support\Meta\MediaRules::validate($file, $rule)) {
                return response()->json(['message' => $err], 422);
            }
        }

        $meta = MetaMediaUploader::upload($file, $company, 'template-headers');
        if (isset($meta['error'])) {
            $this->markMediaUploadResult($template, $meta['error']);
            return response()->json(['message' => $meta['error']], 422);
        }
        $this->markMediaUploadResult($template, null);

        if ($template->header_sample_path) {
            Storage::disk('public')->delete($template->header_sample_path);
        }

        if ($headerFormat !== $template->header_format) {
            $template->update(['header_format' => $headerFormat, 'header' => null]); // media headers carry no inline text
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
        $template = $this->findTemplate($id);

        if ($template->header_sample_path) {
            Storage::disk('public')->delete($template->header_sample_path);
        }

        $this->templates->clearHeaderMedia($id, auth()->user()->company_id);

        return response()->json(['message' => 'Header media removed.']);
    }

    // ── Carousel card media ─────────────────────────────────────────────
    // Each card has one sample image/video, stored under carousel_media[uid] so it survives card edits.
    public function uploadCarouselCardMedia(int $id, string $uid, Request $request): JsonResponse
    {
        $template = $this->findTemplate($id);
        $company  = auth()->user()->company;

        if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $uid)) {
            return response()->json(['message' => 'Invalid card.'], 422);
        }
        if (in_array($template->status, self::LOCKED_STATUSES, true)) {
            return response()->json(['message' => 'This template is under review (or disabled) at Meta, so its cards cannot be changed right now.'], 422);
        }

        $request->validate([
            'file'      => ['required_without:media_url', 'file', 'max:16384'],
            'media_url' => ['required_without:file', 'string', 'max:2000'],
            'format'    => ['nullable', 'in:IMAGE,VIDEO,image,video'],
        ]);
        // The form may be switching IMAGE <-> VIDEO in the same save, so the caller states the intended type.
        $format = strtoupper((string) $request->input('format', $template->carousel_media_format ?: 'IMAGE'));

        $file = $request->file('file');
        if (!$file) {
            $item = \App\Modules\WaChat\Models\MediaLibrary::where('company_id', $company->id)
                ->where('url', $request->input('media_url'))->first();
            if (!$item || !Storage::disk($item->disk ?: 'public')->exists($item->path)) {
                return response()->json(['message' => 'That file was not found in your Media Library.'], 422);
            }
            $disk = Storage::disk($item->disk ?: 'public');
            $file = new \Illuminate\Http\UploadedFile($disk->path($item->path), $item->original_name ?: basename($item->path), $item->mime_type ?: $disk->mimeType($item->path), null, true);
            if ($file->getSize() > 16384 * 1024) {
                return response()->json(['message' => 'That file is larger than 16 MB.'], 422);
            }
        }

        if ($rule = \App\Support\Meta\MediaRules::forHeaderFormat($format)) {
            if ($err = \App\Support\Meta\MediaRules::validate($file, $rule)) {
                return response()->json(['message' => $err], 422);
            }
        }

        $meta = MetaMediaUploader::upload($file, $company, 'template-headers');
        if (isset($meta['error'])) {
            return response()->json(['message' => $meta['error']], 422);
        }

        $media = $template->carousel_media ?? [];
        if (($template->carousel_media_format ?: $format) !== $format) {
            // Switching the whole carousel between image and video: the other cards' samples no longer fit.
            foreach ($media as $k => $m) {
                if ($k !== $uid && !empty($m['path'])) Storage::disk('public')->delete($m['path']);
            }
            $media = [];
        }
        if (!empty($media[$uid]['path'])) {
            Storage::disk('public')->delete($media[$uid]['path']);
        }
        $media[$uid] = ['handle' => $meta['handle'], 'path' => $meta['path'], 'url' => $meta['url']];
        $template->update(['carousel_media' => $media, 'carousel_media_format' => $format]);

        return response()->json(['uid' => $uid, 'url' => $meta['url'], 'format' => $format]);
    }

    public function deleteCarouselCardMedia(int $id, string $uid): JsonResponse
    {
        $template = $this->findTemplate($id);
        $media = $template->carousel_media ?? [];
        if (!empty($media[$uid]['path'])) {
            Storage::disk('public')->delete($media[$uid]['path']);
        }
        unset($media[$uid]);
        $template->update(['carousel_media' => $media ?: null]);

        return response()->json(['message' => 'Card media removed.']);
    }

    /**
     * Every carousel card needs a fresh Meta upload handle. Handles are short-lived and never copied
     * (duplicates) or returned by Meta (synced templates), so re-upload from the stored file / sample URL.
     * Returns a user-facing error, or null when every card is ready.
     */
    private function ensureCarouselHandles(WaTemplate $template, $company): ?string
    {
        $media = $template->carousel_media ?? [];
        foreach (array_values($template->carousel_cards ?? []) as $i => $card) {
            $uid = $card['uid'];
            if (!empty($media[$uid]['handle'])) continue;

            $n    = $i + 1;
            $path = $media[$uid]['path'] ?? null;
            $url  = $media[$uid]['url'] ?? null;
            $tmp  = null;
            if ($path && Storage::disk('public')->exists($path)) {
                $abs  = Storage::disk('public')->path($path);
                $mime = Storage::disk('public')->mimeType($path) ?: null;
                $name = basename($abs);
            } elseif ($url && preg_match('#^https?://#i', $url)) {
                try {
                    $res = Http::timeout(30)->get($url);
                } catch (\Throwable $e) {
                    return "Card {$n}: could not fetch its sample — upload a new one.";
                }
                if (!$res->successful() || $res->body() === '') {
                    return "Card {$n}: its sample is no longer available (Meta links expire) — upload a new one.";
                }
                $tmp  = tempnam(sys_get_temp_dir(), 'tplcar_');
                file_put_contents($tmp, $res->body());
                $abs  = $tmp;
                $mime = trim(explode(';', (string) $res->header('Content-Type'))[0]) ?: null;
                $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'card';
            } else {
                return "Card {$n} needs a sample " . strtolower((string) $template->carousel_media_format) . ' before it can be submitted.';
            }

            try {
                $up = MetaMediaUploader::upload(new \Illuminate\Http\UploadedFile($abs, $name, $mime, null, true), $company, 'template-headers');
            } finally {
                if ($tmp) @unlink($tmp);
            }
            if (isset($up['error'])) {
                return "Card {$n}: " . $up['error'];
            }
            if ($path) Storage::disk('public')->delete($path);
            $media[$uid] = ['handle' => $up['handle'], 'path' => $up['path'], 'url' => $up['url']];
        }
        $template->update(['carousel_media' => $media ?: null]);

        return null;
    }

    // ── Footer media — stored for reference only, never sent to Meta ──────
    // (Meta's Graph API does not support media on FOOTER components.)
    public function uploadFooterMedia(int $id, Request $request): JsonResponse
    {
        $template = $this->findTemplate($id);
        $request->validate(['file' => ['required', 'file', 'max:16384']]);

        $path = $request->file('file')->store('template-footers', 'public');
        if (!$path) {
            return response()->json(['message' => 'Failed to save the file.'], 422);
        }
        if ($template->footer_media_path) {
            Storage::disk('public')->delete($template->footer_media_path);
        }
        $url = Storage::disk('public')->url($path);
        $this->templates->attachFooterMedia($id, $template->company_id, null, $path, $url);

        return response()->json(['footer_media_url' => $url, 'footer_media_path' => $path]);
    }

    public function deleteFooterMedia(int $id): JsonResponse
    {
        $template = $this->findTemplate($id);

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
        $template = $this->findTemplate($id);
        $buttons  = $template->buttons ?? [];

        if (!isset($buttons[$buttonId])) {
            return response()->json(['message' => 'Button not found on this template.'], 404);
        }

        $request->validate(['file' => ['required', 'file', 'max:16384']]);

        $path = $request->file('file')->store('template-buttons', 'public');
        if (!$path) {
            return response()->json(['message' => 'Failed to save the file.'], 422);
        }
        if (!empty($buttons[$buttonId]['media_path'])) {
            Storage::disk('public')->delete($buttons[$buttonId]['media_path']);
        }
        $url = Storage::disk('public')->url($path);
        $this->templates->attachButtonMedia($id, $template->company_id, $buttonId, null, $path, $url);

        return response()->json(['button_id' => $buttonId, 'media_url' => $url, 'media_path' => $path]);
    }

    public function deleteButtonMedia(int $id, int $buttonId): JsonResponse
    {
        $template = $this->findTemplate($id);
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
            if (in_array($template->status, self::LIVE_STATUSES, true)) {
                return; // the live template is untouched on Meta; the caller already returns this error
            }
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
        $source  = $this->findTemplate($id);

        $copiedHeaderPath = null;
        if ($source->header_sample_path && Storage::disk('public')->exists($source->header_sample_path)) {
            $ext = pathinfo($source->header_sample_path, PATHINFO_EXTENSION);
            $copiedHeaderPath = 'template-headers/' . uniqid('dup_') . ($ext ? ".{$ext}" : '');
            Storage::disk('public')->copy($source->header_sample_path, $copiedHeaderPath);
        }

        $duplicate = WaTemplate::create([
            'company_id'         => $company->id,
            // Template names must be unique per language, so the copy gets a throwaway name
            // (…_copy_xxxx) that the user is expected to replace in the edit modal.
            'name'               => $source->name . '_copy_' . substr(uniqid(), -4),
            'wa_phone_number_id' => $source->wa_phone_number_id, // keeps the copy visible to number-restricted users
            'message_send_ttl_seconds'         => $source->message_send_ttl_seconds,
            'auth_delivery_method'             => $source->auth_delivery_method,
            'auth_add_expiry'                  => $source->auth_add_expiry,
            'auth_code_expiration_minutes'     => $source->auth_code_expiration_minutes,
            'auth_add_security_recommendation' => $source->auth_add_security_recommendation,
            'auth_apps'                        => $source->auth_apps,
            'auth_zero_tap_terms_accepted'     => $source->auth_zero_tap_terms_accepted,
            'category'           => $source->category,
            'language'           => $source->language,
            'body'               => $source->body,
            'body_examples'      => $source->body_examples,
            'header_format'      => $source->header_format,
            'header'             => $source->header,
            'header_example'     => $source->header_example,
            // Meta upload handles are short-lived, so they are NOT copied — submit() re-uploads the
            // local copy below to get a fresh one (see reuploadHeaderSample).
            'header_handle'      => null,
            'header_sample_path' => $copiedHeaderPath,
            'header_sample_url'  => $copiedHeaderPath ? Storage::disk('public')->url($copiedHeaderPath) : null,
            'footer'             => $source->footer,
            'template_type'      => $source->template_type,
            'carousel_media_format' => $source->carousel_media_format,
            'carousel_cards'     => $source->carousel_cards,
            'carousel_media'     => $this->copyCarouselMedia($source->carousel_media),
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

    /** Copy each card's stored sample file so the duplicate survives the original being edited; handles are not copied. */
    private function copyCarouselMedia(?array $media): ?array
    {
        if (!$media) return null;
        $out = [];
        foreach ($media as $uid => $m) {
            $path = $m['path'] ?? null;
            if ($path && Storage::disk('public')->exists($path)) {
                $ext  = pathinfo($path, PATHINFO_EXTENSION);
                $copy = 'template-headers/' . uniqid('dup_') . ($ext ? ".{$ext}" : '');
                Storage::disk('public')->copy($path, $copy);
                $out[$uid] = ['handle' => null, 'path' => $copy, 'url' => Storage::disk('public')->url($copy)];
            } elseif (!empty($m['url'])) {
                $out[$uid] = ['handle' => null, 'path' => null, 'url' => $m['url']];
            }
        }
        return $out ?: null;
    }

    // True when a media header can be (re)uploaded to Meta from what we hold: a local sample file,
    // or — for templates synced from Meta — the sample URL Meta itself returned.
    private function canReuploadHeader(WaTemplate $template): bool
    {
        return ($template->header_sample_path && Storage::disk('public')->exists($template->header_sample_path))
            || preg_match('#^https?://#i', (string) $template->header_sample_url) === 1;
    }

    // Re-uploads a template's header sample to Meta and saves the fresh handle. Used when a duplicate is
    // submitted (upload handles are short-lived, so they are not copied) and when a template synced from
    // Meta is edited (Meta never hands back an upload handle). Returns an error message, or null on success.
    private function reuploadHeaderSample(WaTemplate $template, $company): ?string
    {
        $tmp = null;
        if ($template->header_sample_path && Storage::disk('public')->exists($template->header_sample_path)) {
            $abs  = Storage::disk('public')->path($template->header_sample_path);
            $mime = Storage::disk('public')->mimeType($template->header_sample_path) ?: null;
            $name = basename($abs);
        } else {
            try {
                $res = Http::timeout(30)->get((string) $template->header_sample_url);
            } catch (\Throwable $e) {
                return 'Could not fetch the existing header sample — upload a new one.';
            }
            if (!$res->successful() || $res->body() === '') {
                return 'The existing header sample is no longer available (Meta links expire) — upload a new one.';
            }
            $tmp  = tempnam(sys_get_temp_dir(), 'tplhdr_');
            file_put_contents($tmp, $res->body());
            $abs  = $tmp;
            $mime = trim(explode(';', (string) $res->header('Content-Type'))[0]) ?: null;
            $name = basename((string) parse_url($template->header_sample_url, PHP_URL_PATH)) ?: 'header';
        }

        try {
            $meta = MetaMediaUploader::upload(new \Illuminate\Http\UploadedFile($abs, $name, $mime, null, true), $company, 'template-headers');
        } finally {
            if ($tmp) @unlink($tmp);
        }
        if (isset($meta['error'])) {
            return $meta['error'];
        }

        if ($template->header_sample_path) {
            Storage::disk('public')->delete($template->header_sample_path); // replaced by the freshly stored copy
        }
        $this->templates->attachHeaderMedia($template->id, $template->company_id, $meta['handle'], $meta['path'], $meta['url']);
        return null;
    }

    // ── Validation shared by store/update ──────────────────────────────
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $isAuth = strtoupper((string) $request->input('category')) === 'AUTHENTICATION';

        $d = $request->validate([
            'name'            => [$isUpdate ? 'sometimes' : 'required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:100'],
            'category'        => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'language'        => ['required', 'string', 'max:10'],
            // Meta auto-generates the body copy for AUTHENTICATION templates — no free text allowed.
            'body'            => [$isAuth ? 'nullable' : 'required', 'string', 'max:1024', function ($attr, $value, $fail) {
                // Keep body copy readable and out of Meta's spam heuristics: at most 10 emojis.
                $emojis = @preg_match_all('/\p{Extended_Pictographic}/u', (string) $value);
                if ($emojis !== false && $emojis > 10) {
                    $fail("The body can have at most 10 emojis ({$emojis} found).");
                }
                // The other two things Meta rejects with "message body can't have more than two consecutive
                // newline characters, only have parameters, or have more than 10 emojis" — caught here so
                // the template never makes the round trip just to be refused.
                $text = preg_replace('/\r\n?/', "\n", (string) $value);
                if (preg_match('/\n{3,}/', $text)) {
                    $fail('The body cannot have more than two line breaks in a row.');
                }
                if (trim($text) !== '' && trim(preg_replace('/\{\{\s*\d+\s*\}\}/', '', $text)) === '') {
                    $fail('The body cannot consist only of variables — add some text around them.');
                } elseif (preg_match('/^\s*\{\{\s*\d+\s*\}\}/', $text)) {
                    $fail('The body cannot start with a variable.');
                } elseif (preg_match('/\{\{\s*\d+\s*\}\}\s*$/', $text)) {
                    $fail('The body cannot end with a variable.');
                }
            }],
            'body_examples'   => ['nullable', 'array'],
            'body_examples.*' => ['string', 'max:255'],
            // No header/footer/buttons at all on AUTHENTICATION — Meta rejects the template if present.
            'header_format'   => ['nullable', Rule::in($isAuth ? ['TEXT'] : ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header'          => ['nullable', 'string', 'max:60', function ($attr, $value, $fail) {
                if ($value === null || $value === '') return;
                if (preg_match('/[\r\n]/', $value)) {
                    $fail('The header cannot contain line breaks.');
                }
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $value, $m);
                if (count($m[1]) > 1 || ($m[1] && (int) $m[1][0] !== 1)) {
                    $fail('The header supports at most one variable, and it must be {{1}}.');
                }
            }],
            'header_example'  => ['nullable', 'string', 'max:255'],
            'footer'          => ['nullable', 'string', 'max:60', function ($attr, $value, $fail) {
                // Meta rejects footers with emojis or line breaks (error_subcode 2388073).
                if ($value !== null && (preg_match('/[\r\n]/', $value) || @preg_match('/\p{Extended_Pictographic}/u', $value) === 1)) {
                    $fail('The footer cannot contain emojis or line breaks.');
                }
                if ($value !== null && preg_match('/\{\{\s*\d+\s*\}\}/', $value)) {
                    $fail('The footer cannot contain variables.');
                }
            }],
            'buttons'         => ['nullable', 'array', 'max:3'],
            // SURVEY_FORM is our own button kind — Meta only ever sees it as a QUICK_REPLY
            // (see buildComponents). Tapping it starts the linked survey (handled in the webhook).
            'buttons.*.type'  => ['required_with:buttons', 'in:QUICK_REPLY,URL,PHONE_NUMBER,SURVEY_FORM,COPY_CODE'],
            // COPY_CODE has no label of its own — Meta renders it as "Copy offer code".
            'buttons.*.text'  => ['nullable', 'required_unless:buttons.*.type,COPY_CODE', 'string', 'max:25'],
            'buttons.*.example' => ['nullable', 'required_if:buttons.*.type,COPY_CODE', 'string', 'max:15', 'regex:/^[A-Za-z0-9]+$/'],
            'buttons.*.url'   => ['nullable', 'required_if:buttons.*.type,URL', 'url'],
            // Meta (#192) rejects numbers without a country code — require "+" then 7-15 digits
            // (spaces, dashes and brackets are tolerated and stripped before sending).
            'buttons.*.phone_number'   => ['nullable', 'required_if:buttons.*.type,PHONE_NUMBER', 'string', 'max:25', 'regex:/^\+[\d\s\-()]{7,24}$/'],
            'buttons.*.survey_form_id' => ['nullable', 'required_if:buttons.*.type,SURVEY_FORM', 'integer'],

            // Marketing message validity period: 12h–7d. Null = Meta's standard 12 hours.
            'message_send_ttl_seconds' => ['nullable', 'integer', Rule::in([43200, 86400, 172800, 259200, 345600, 604800])],

            // ── CAROUSEL templates (MARKETING only): a body plus 2–10 swipeable cards ──
            'template_type'            => ['nullable', 'in:standard,carousel'],
            'carousel_media_format'    => ['nullable', 'in:IMAGE,VIDEO'],
            'carousel_cards'           => ['nullable', 'array', 'max:10'],
            'carousel_cards.*.uid'     => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{4,40}$/', 'distinct'],
            'carousel_cards.*.body'    => ['required', 'string', 'max:160'],
            'carousel_cards.*.buttons' => ['required', 'array', 'min:1', 'max:2'],
            'carousel_cards.*.buttons.*.type' => ['required', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
            'carousel_cards.*.buttons.*.text' => ['required', 'string', 'max:25'],
            'carousel_cards.*.buttons.*.url'  => ['nullable', 'required_if:carousel_cards.*.buttons.*.type,URL', 'url'],
            'carousel_cards.*.buttons.*.phone_number' => ['nullable', 'required_if:carousel_cards.*.buttons.*.type,PHONE_NUMBER', 'string', 'max:25', 'regex:/^\+[\d\s\-()]{7,24}$/'],

            // ── AUTHENTICATION-only fields ──────────────────────────────
            'auth_delivery_method'             => [$isAuth ? 'required' : 'nullable', 'in:copy_code,one_tap,zero_tap'],
            'auth_add_expiry'                  => ['nullable', 'boolean'],
            'auth_code_expiration_minutes'      => ['nullable', 'integer', 'min:1', 'max:90'],
            'auth_add_security_recommendation' => ['nullable', 'boolean'],
            'auth_apps'                         => [
                Rule::requiredIf($isAuth && in_array($request->input('auth_delivery_method'), ['one_tap', 'zero_tap'])),
                'nullable', 'array', 'max:5',
            ],
            'auth_apps.*.package_name'    => ['required_with:auth_apps', 'string', 'max:224'],
            'auth_apps.*.signature_hash'  => ['required_with:auth_apps', 'string', 'max:50'],
            'auth_zero_tap_terms_accepted' => [
                Rule::requiredIf($isAuth && $request->input('auth_delivery_method') === 'zero_tap'),
                'nullable', 'boolean',
            ],
        ]);

        if ($isAuth && $d['auth_delivery_method'] === 'zero_tap' && empty($d['auth_zero_tap_terms_accepted'])) {
            throw ValidationException::withMessages([
                'auth_zero_tap_terms_accepted' => 'You must accept the zero-tap terms to submit this template.',
            ]);
        }

        $d['header_format'] = $isAuth ? 'TEXT' : ($d['header_format'] ?? 'TEXT');

        $isCarousel = !$isAuth && ($d['template_type'] ?? 'standard') === 'carousel';
        $d['template_type'] = $isCarousel ? 'carousel' : 'standard';
        if ($isCarousel) {
            $this->validateCarousel($d);
            // A carousel's media, footer and buttons all live on the cards — Meta refuses them at the top level.
            $d['header_format'] = 'TEXT';
            $d['header'] = null;
            $d['header_example'] = null;
            $d['footer'] = null;
            $d['buttons'] = null;
        } else {
            $d['carousel_media_format'] = null;
            $d['carousel_cards'] = null;
        }

        if (!$isAuth) {
            // Meta rejects a body whose sample values don't match its {{n}} placeholders one-for-one.
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) ($d['body'] ?? ''), $m);
            $needed = $m[1] ? max(array_map('intval', $m[1])) : 0;
            if ($needed !== count($d['body_examples'] ?? [])) {
                throw ValidationException::withMessages([
                    'body_examples' => $needed
                        ? "The body uses {$needed} variable(s) — provide exactly {$needed} sample value(s)."
                        : 'The body has no variables, so no sample values should be sent.',
                ]);
            }

            // Meta rejects quick-reply buttons interleaved with URL/phone/copy-code buttons: they must
            // form one contiguous group (all first or all last).
            // Button rules Meta enforces on submit (caught here so nothing is sent just to be refused).
            $counts = ['PHONE_NUMBER' => 0, 'URL' => 0, 'COPY_CODE' => 0];
            $seen   = [];
            foreach (array_values($d['buttons'] ?? []) as $i => $b) {
                $n    = $i + 1;
                $type = $b['type'];
                if (isset($counts[$type])) $counts[$type]++;

                if ($type !== 'COPY_CODE') {
                    $label = trim((string) ($b['text'] ?? ''));
                    if ($label === '') {
                        throw ValidationException::withMessages(['buttons' => "Button {$n}: the button text is required."]);
                    }
                    if (preg_match('/[\r\n]|\{\{/', $label)) {
                        throw ValidationException::withMessages(['buttons' => "Button {$n}: the text cannot contain line breaks or variables."]);
                    }
                    $key = mb_strtolower($label);
                    if (isset($seen[$key])) {
                        throw ValidationException::withMessages(['buttons' => "Button {$n}: \"{$label}\" is used by another button — every button needs its own text."]);
                    }
                    $seen[$key] = true;
                }

                if ($type === 'URL') {
                    $url = trim((string) ($b['url'] ?? ''));
                    if (!preg_match('#^https?://\S+$#i', $url) || strlen($url) > 2000) {
                        throw ValidationException::withMessages(['buttons' => "Button {$n}: enter a full website link starting with https:// (no spaces, max 2000 characters)."]);
                    }
                    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                    if (preg_match('/(^|\.)(wa\.me|whatsapp\.com)$/', $host)) {
                        throw ValidationException::withMessages(['buttons' => "Button {$n}: a website button cannot link to WhatsApp (wa.me / whatsapp.com) — use a call or quick-reply button instead."]);
                    }
                }
            }
            if ($counts['PHONE_NUMBER'] > 1) {
                throw ValidationException::withMessages(['buttons' => 'A template can have only one "Call phone number" button.']);
            }
            if ($counts['URL'] > 2) {
                throw ValidationException::withMessages(['buttons' => 'A template can have at most two "Visit website" buttons.']);
            }
            if ($counts['COPY_CODE'] > 1) {
                throw ValidationException::withMessages(['buttons' => 'A template can have only one "Copy offer code" button.']);
            }

            $kinds = collect($d['buttons'] ?? [])->map(fn ($b) => in_array($b['type'], ['QUICK_REPLY', 'SURVEY_FORM'], true) ? 'Q' : 'A')->implode('');
            if (preg_match('/Q+A+Q+|A+Q+A+/', $kinds)) {
                throw ValidationException::withMessages([
                    'buttons' => 'Keep quick-reply / survey buttons together — put them all before or all after the website, call and copy-code buttons.',
                ]);
            }
        }

        if ($isAuth) {
            // Authentication templates carry none of these — Meta generates fixed copy instead.
            $d['header'] = null;
            $d['footer'] = null;
            $d['buttons'] = null;
            $d['message_send_ttl_seconds'] = null;
            $d['body'] = $d['body'] ?? '';
            $d['auth_add_expiry'] = $d['auth_add_expiry'] ?? true;
            $d['auth_code_expiration_minutes'] = $d['auth_code_expiration_minutes'] ?? 20;
            $d['auth_add_security_recommendation'] = $d['auth_add_security_recommendation'] ?? true;
        } elseif ($d['header_format'] !== 'TEXT') {
            $d['header'] = null; // media headers don't carry inline text
        }

        // Custom validity only exists for MARKETING templates.
        if (($d['category'] ?? null) !== 'MARKETING') {
            $d['message_send_ttl_seconds'] = null;
        }

        return $d;
    }

    /** Meta's carousel rules, checked before anything is sent so a template is never submitted just to be refused. */
    private function validateCarousel(array &$d): void
    {
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages([$field => $msg]);

        if (strtoupper((string) ($d['category'] ?? '')) !== 'MARKETING') {
            $fail('template_type', 'Carousel templates are only available in the Marketing category.');
        }
        if (empty($d['carousel_media_format'])) {
            $fail('carousel_media_format', 'Choose Image or Video for the carousel cards.');
        }
        $cards = array_values($d['carousel_cards'] ?? []);
        if (count($cards) < 2 || count($cards) > 10) {
            $fail('carousel_cards', 'A carousel needs between 2 and 10 cards.');
        }

        $shape = null;
        foreach ($cards as $i => $card) {
            $n = $i + 1;
            if (preg_match('/\{\{/', $card['body'])) {
                $fail('carousel_cards', "Card {$n}: variables aren't supported in card text yet.");
            }
            if (preg_match('/\n{3,}/', preg_replace('/\r\n?/', "\n", $card['body']))) {
                $fail('carousel_cards', "Card {$n}: the text cannot have more than two line breaks in a row.");
            }
            $seen = [];
            foreach ($card['buttons'] as $b) {
                if (preg_match('/[\r\n]|\{\{/', $b['text'])) {
                    $fail('carousel_cards', "Card {$n}: button text cannot contain line breaks or variables.");
                }
                $k = mb_strtolower(trim($b['text']));
                if (isset($seen[$k])) {
                    $fail('carousel_cards', "Card {$n}: \"{$b['text']}\" is used by two buttons — each needs its own text.");
                }
                $seen[$k] = true;
                if ($b['type'] === 'URL') {
                    $host = strtolower((string) parse_url((string) $b['url'], PHP_URL_HOST));
                    if (preg_match('/(^|\.)(wa\.me|whatsapp\.com)$/', $host)) {
                        $fail('carousel_cards', "Card {$n}: a website button cannot link to WhatsApp.");
                    }
                }
            }
            // Meta: every card must have the same buttons, in the same order (labels and links may differ).
            $thisShape = implode(',', array_column($card['buttons'], 'type'));
            if ($shape !== null && $thisShape !== $shape) {
                $fail('carousel_cards', "Card {$n}: every card must use the same button types in the same order as card 1.");
            }
            $shape ??= $thisShape;
            if (substr_count($thisShape, 'PHONE_NUMBER') > 1) {
                $fail('carousel_cards', "Card {$n}: only one call button is allowed per card.");
            }
        }
        $d['carousel_cards'] = $cards;
    }

    /** Message naming the first carousel card that has no sample media at Meta yet, or null when all are ready. */
    private function carouselMediaProblem(WaTemplate $template): ?string
    {
        if ($template->template_type !== 'carousel') return null;
        foreach (array_values($template->carousel_cards ?? []) as $i => $card) {
            if (empty($template->carousel_media[$card['uid']]['handle'])) {
                return 'Card ' . ($i + 1) . ' needs a sample ' . strtolower((string) $template->carousel_media_format) . ' before it can be submitted.';
            }
        }
        return null;
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
            Log::error('[template-submit] step 1/4 FAILED: missing WhatsApp credentials', [
                'template_id'      => $template->id,
                'company_id'       => $company->id,
                'has_token'        => (bool) $company->decrypt_wa_access_token,
                'has_business_id'  => (bool) $company->wa_business_id,
            ]);
            // Without this the row stayed "pending" (set by update()) although nothing reached Meta.
            $template->update([
                'status'           => 'error',
                'rejection_reason' => 'WhatsApp credentials are not configured — connect WhatsApp in Settings, then submit again.',
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
            ? MetaGraph::templateNodeUrl($template->wa_template_id)
            : MetaGraph::messageTemplatesUrl($company->wa_business_id);

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
                    ->post($endpoint, array_filter([
                        'name'       => $d['name'],
                        'category'   => $d['category'],
                        'language'   => $d['language'],
                        'components' => $components,
                        'message_send_ttl_seconds' => $d['message_send_ttl_seconds'] ?? null,
                    ], fn ($v) => !is_null($v)));
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
            if ($subcode === 2388339) {
                $reason = \App\Support\Meta\MetaGraph::wabaIdProblem($company);
            }

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
                ->get(MetaGraph::messageTemplatesUrl($company->wa_business_id), [
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
        if (strtoupper($d['category'] ?? '') === 'AUTHENTICATION') {
            return $this->buildAuthComponents($d);
        }

        if (($d['template_type'] ?? 'standard') === 'carousel') {
            return $this->buildCarouselComponents($d);
        }

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
            $components[] = ['type' => 'BUTTONS', 'buttons' => $this->mapButtonsForMeta($d['buttons'])];
        }

        return $components;
    }

    // BODY + CAROUSEL: each card is HEADER (image/video sample) + BODY + BUTTONS.
    private function buildCarouselComponents(array $d): array
    {
        $body = ['type' => 'BODY', 'text' => $d['body']];
        if (!empty($d['body_examples'])) {
            $body['example'] = ['body_text' => [array_values($d['body_examples'])]];
        }

        $format = strtoupper((string) ($d['carousel_media_format'] ?? 'IMAGE'));
        $cards = collect($d['carousel_cards'] ?? [])->map(fn ($card) => [
            'components' => [
                [
                    'type'    => 'HEADER',
                    'format'  => $format,
                    'example' => ['header_handle' => [$d['carousel_media'][$card['uid']]['handle'] ?? '']],
                ],
                ['type' => 'BODY', 'text' => $card['body']],
                ['type' => 'BUTTONS', 'buttons' => $this->mapButtonsForMeta($card['buttons'] ?? [])],
            ],
        ])->values()->all();

        return [$body, ['type' => 'CAROUSEL', 'cards' => $cards]];
    }

    /** Our button shape -> Meta's. Local-only fields (survey_form_id, media) are dropped. */
    private function mapButtonsForMeta(array $list): array
    {
        $buttons = collect($list)->map(function ($b) {
            $type    = $b['type'] ?? 'QUICK_REPLY';
            // A SURVEY_FORM button is a plain quick-reply as far as Meta is concerned —
            // the survey link lives only in our DB and is resolved from the tap webhook.
            if ($type === 'SURVEY_FORM') {
                $type = 'QUICK_REPLY';
            }
            if ($type === 'COPY_CODE') {
                return ['type' => 'COPY_CODE', 'example' => (string) ($b['example'] ?? '')];
            }
            $isPhone = $type === 'PHONE_NUMBER';

            // Frontend may send the number under a dedicated `phone_number` field, or
            // (legacy behavior) under `text` itself — support both so nothing breaks.
            $phoneNumber = $b['phone_number'] ?? ($isPhone ? ($b['text'] ?? null) : null);

            return array_filter([
                'type'         => $type,
                'text'         => $isPhone ? ($b['label'] ?? $b['text'] ?? 'Call') : $b['text'],
                'url'          => $type === 'URL' ? ($b['url'] ?? null) : null,
                'phone_number' => $isPhone && $phoneNumber ? '+' . preg_replace('/\D+/', '', $phoneNumber) : null,
            ], fn($v) => !is_null($v));
        })->toArray(); // media / survey_form_id fields intentionally dropped here

        return $buttons;
    }

    // ── AUTHENTICATION-specific component shape ─────────────────────────
    // Meta generates the OTP body/footer copy itself — we only control the
    // flags (security recommendation, code expiry) and the OTP button config.
    private function buildAuthComponents(array $d): array
    {
        $components = [
            [
                'type' => 'BODY',
                'add_security_recommendation' => (bool) ($d['auth_add_security_recommendation'] ?? true),
            ],
        ];

        if (!empty($d['auth_add_expiry'])) {
            $components[] = [
                'type' => 'FOOTER',
                'code_expiration_minutes' => (int) ($d['auth_code_expiration_minutes'] ?? 20),
            ];
        }

        $method = $d['auth_delivery_method'] ?? 'copy_code';
        $apps = collect($d['auth_apps'] ?? [])
            ->map(fn ($a) => ['package_name' => $a['package_name'], 'signature_hash' => $a['signature_hash']])
            ->values()
            ->toArray();

        $otpButton = match ($method) {
            'zero_tap' => array_filter([
                'type'                    => 'OTP',
                'otp_type'                => 'ZERO_TAP',
                'zero_tap_terms_accepted' => true,
                'supported_apps'          => $apps,
            ]),
            'one_tap' => array_filter([
                'type'           => 'OTP',
                'otp_type'       => 'ONE_TAP',
                'supported_apps' => $apps,
            ]),
            default => [
                'type'     => 'OTP',
                'otp_type' => 'COPY_CODE',
            ],
        };

        $components[] = ['type' => 'BUTTONS', 'buttons' => [$otpButton]];

        return $components;
    }

    // ── Delete template from platform + Meta ─────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $template = $this->findTemplate($id);

        $company = auth()->user()->company;

        if ($template->wa_template_id && $company->decrypt_wa_access_token && $company->wa_business_id) {
            try {
                // Params go in the query string: Laravel sends Http::delete()'s array as a JSON body,
                // which Graph's DELETE endpoints don't reliably read.
                $res = Http::withToken($company->decrypt_wa_access_token)
                    ->timeout(20)
                    ->delete(MetaGraph::messageTemplatesUrl($company->wa_business_id) . '?' . http_build_query([
                        'hsm_id' => $template->wa_template_id,
                        'name'   => $template->name,
                    ]));
                if ($res->failed()) {
                    Log::warning('Meta template delete failed', ['template_id' => $template->id, 'body' => $res->json()]);
                }
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

        $fieldsWithTtl = 'id,name,category,language,status,rejected_reason,components,message_send_ttl_seconds';
        $fieldsPlain   = 'id,name,category,language,status,rejected_reason,components';
        $url    = MetaGraph::messageTemplatesUrl($company->wa_business_id);
        $params = ['fields' => $fieldsWithTtl, 'limit' => 100];
        $updated = 0;

        // Follow Meta's paging cursors so templates beyond the first 100 are not silently skipped.
        do {
            try {
                $response = Http::withToken($company->decrypt_wa_access_token)->timeout(20)->get($url, $params);
                // Some API versions reject message_send_ttl_seconds on read — retry once without it.
                if ($response->failed() && ($params['fields'] ?? null) === $fieldsWithTtl) {
                    $params['fields'] = $fieldsPlain;
                    $response = Http::withToken($company->decrypt_wa_access_token)->timeout(20)->get($url, $params);
                }
            } catch (ConnectionException $e) {
                Log::warning('Meta sync timed out.', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                return response()->json(['message' => 'Timed out contacting Meta. Please try again.'], 422);
            }

            if ($response->failed()) {
                return response()->json(['message' => $response->json('error.message')], 422);
            }

            foreach ($response->json('data', []) as $meta) {
                // skip malformed entries defensively instead of crashing the whole sync
                if (empty($meta['name']) || empty($meta['id'])) {
                    continue;
                }

                // Match on Meta's template id first; otherwise on name + language — the same name
                // legitimately exists once per language, so name alone would overwrite siblings.
                $existing = WaTemplate::where('company_id', $company->id)->where('wa_template_id', $meta['id'])->first()
                    ?? WaTemplate::where('company_id', $company->id)
                        ->where('name', $meta['name'])
                        ->where('language', $meta['language'] ?? 'en')
                        ->first();

                $attrs = $this->templates->attributesFromMeta($meta, $existing);
                // Keep local-only button fields (survey_form_id) when the button list is unchanged in shape.
                $attrs['buttons'] = $this->mergeLocalButtonFields($existing?->buttons, $attrs['buttons']);

                if ($existing) {
                    $existing->update($attrs);
                } else {
                    WaTemplate::create(array_merge($attrs, ['company_id' => $company->id, 'name' => $meta['name']]));
                }
                $updated++;
            }

            $url    = $response->json('paging.next'); // already carries the query string
            $params = [];
        } while ($url);

        return response()->json(['message' => "Synced {$updated} templates from Meta."]);
    }

    /** Keep local-only button fields (survey_form_id) when Meta's synced button at the same position matches. */
    private function mergeLocalButtonFields(?array $local, ?array $synced): ?array
    {
        if (!$synced || !$local) return $synced;
        foreach ($synced as $i => $b) {
            $old = $local[$i] ?? null;
            if ($old && ($old['text'] ?? null) === ($b['text'] ?? null) && !empty($old['survey_form_id'])) {
                $synced[$i]['type'] = 'SURVEY_FORM';
                $synced[$i]['survey_form_id'] = $old['survey_form_id'];
            }
        }
        return $synced;
    }

    // ── Per-template single status refresh from Meta ───────────────────────
    public function syncSingle(int $id): JsonResponse
    {
        $this->findTemplate($id); // authorise before the service re-reads it by company only
        try {
            $template = $this->templates->syncSingleFromMeta($id, auth()->user()->company_id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            // A Meta/credentials problem is not a server crash — tell the user what to fix.
            Log::warning('[template-sync] failed', ['template_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Synced from Meta.', 'template' => $template]);
    }
}
