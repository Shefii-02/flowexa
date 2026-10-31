<?php

namespace App\Modules\Template\Services;

use App\Models\WaTemplate;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Support\Meta\MetaGraph;
use Illuminate\Support\Facades\Http;

class TemplateService
{
    /**
     * @param array<int>|null $allowedNumberIds Restricts to templates on these WA Cloud
     *   numbers, plus any template with no number attached (company-wide/shared). Null
     *   means unrestricted — see User::allowedAccountIds('phone_number').
     */
    public function list(int $companyId, array $filters = [], ?array $allowedNumberIds = null): LengthAwarePaginator
    {
        return WaTemplate::where('company_id', $companyId)
            ->when($allowedNumberIds !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereNull('wa_phone_number_id')->orWhereIn('wa_phone_number_id', $allowedNumberIds)
            ))
            ->when(
                $filters['search'] ?? null,
                fn($q) =>
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('body', 'like', "%{$filters['search']}%")
            )
            ->when($filters['status'] ?? null, fn($q) => $q->where('status', $filters['status']))
            ->when($filters['category'] ?? null, fn($q) => $q->where('category', $filters['category']))
            ->latest()
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /** @param array<int>|null $allowedNumberIds see list() */
    public function show(int $id, int $companyId, ?array $allowedNumberIds = null): WaTemplate
    {
        $template = WaTemplate::where('id', $id)->where('company_id', $companyId)->firstOrFail();

        if ($allowedNumberIds !== null && $template->wa_phone_number_id !== null
            && !in_array($template->wa_phone_number_id, $allowedNumberIds, true)) {
            abort(404, 'Template not found.');
        }

        return $template;
    }

    public function create(int $companyId, array $data): WaTemplate
    {
        return WaTemplate::create([
            'company_id'          => $companyId,
            'wa_phone_number_id'  => $data['wa_phone_number_id'] ?? null,
            'name'                => $data['name'],
            'wa_template_id'      => $data['wa_template_id'] ?? null,
            'category'            => $data['category'],
            'template_type'       => $data['template_type'] ?? 'standard',
            'carousel_media_format' => $data['carousel_media_format'] ?? null,
            'carousel_cards'      => $data['carousel_cards'] ?? null,
            'lto_text'            => $data['lto_text'] ?? null,
            'lto_has_expiration'  => $data['lto_has_expiration'] ?? true,
            'lto_expiry_hours'    => $data['lto_expiry_hours'] ?? 72,
            'language'            => $data['language'] ?? 'en',
            'body'                => $data['body'],
            'body_examples'       => $data['body_examples'] ?? null,
            'header_format'       => $data['header_format'] ?? 'TEXT',
            'header'              => $data['header'] ?? null,
            'header_example'      => $data['header_example'] ?? null,
            'footer'              => $data['footer'] ?? null,
            'buttons'             => $data['buttons'] ?? null,
            'message_send_ttl_seconds' => $data['message_send_ttl_seconds'] ?? null,
            'status'              => $data['status'] ?? 'draft',
            'rejection_reason'    => $data['rejection_reason'] ?? null,
            // AUTHENTICATION-only fields — harmless nulls/defaults for other categories
            'auth_delivery_method'             => $data['auth_delivery_method'] ?? null,
            'auth_add_expiry'                  => $data['auth_add_expiry'] ?? true,
            'auth_code_expiration_minutes'      => $data['auth_code_expiration_minutes'] ?? 20,
            'auth_add_security_recommendation' => $data['auth_add_security_recommendation'] ?? true,
            'auth_apps'                         => $data['auth_apps'] ?? null,
            'auth_zero_tap_terms_accepted'      => $data['auth_zero_tap_terms_accepted'] ?? false,
        ]);
    }

    public function update(int $id, int $companyId, array $data): WaTemplate
    {
        $template = $this->show($id, $companyId);

        $template->update(array_filter([
            'name'                => $data['name'] ?? null,
            'wa_template_id'      => $data['wa_template_id'] ?? null,
            'category'            => $data['category'] ?? null,
            'language'            => $data['language'] ?? null,
            'body'                => $data['body'] ?? null,
            'header'              => $data['header'] ?? null,
            'header_format'       => $data['header_format'] ?? null,
            'header_example'      => $data['header_example'] ?? null,
            'footer'              => $data['footer'] ?? null,
            'status'              => $data['status'] ?? null,
            'wa_phone_number_id'  => $data['wa_phone_number_id'] ?? null,
            'rejection_reason'    => $data['rejection_reason'] ?? null,
            'auth_delivery_method'             => $data['auth_delivery_method'] ?? null,
            'auth_code_expiration_minutes'      => $data['auth_code_expiration_minutes'] ?? null,
        ], fn($v) => !is_null($v)));

        // Legitimately nullable/emptyable/false-able fields can't go through array_filter
        // above (it would silently drop an intentional empty array, empty string, or
        // `false` boolean along with genuine nulls) — set them directly when present.
        if (array_key_exists('body_examples', $data)) {
            $template->body_examples = $data['body_examples'];
        }
        if (array_key_exists('message_send_ttl_seconds', $data)) {
            $template->message_send_ttl_seconds = $data['message_send_ttl_seconds'];
        }
        if (array_key_exists('buttons', $data)) {
            $template->buttons = $data['buttons'];
        }
        foreach (['lto_text', 'lto_expiry_hours'] as $k) {
            if (array_key_exists($k, $data)) $template->{$k} = $data[$k];
        }
        if (array_key_exists('lto_has_expiration', $data)) {
            $template->lto_has_expiration = (bool) $data['lto_has_expiration'];
        }
        if (array_key_exists('template_type', $data)) {
            $template->template_type = $data['template_type'] ?: 'standard';
        }
        if (array_key_exists('carousel_media_format', $data)) {
            // Image <-> video: the old samples no longer fit the new type, so drop them (and their files).
            if ($template->carousel_media_format && $data['carousel_media_format'] !== $template->carousel_media_format) {
                foreach ($template->carousel_media ?? [] as $m) {
                    if (!empty($m['path'])) \Illuminate\Support\Facades\Storage::disk('public')->delete($m['path']);
                }
                $template->carousel_media = null;
            }
            $template->carousel_media_format = $data['carousel_media_format'];
        }
        if (array_key_exists('carousel_cards', $data)) {
            $template->carousel_cards = $data['carousel_cards'];
            // Drop the sample media of cards that were removed (and the files behind them).
            $keep  = collect($data['carousel_cards'] ?? [])->pluck('uid')->all();
            $media = $template->carousel_media ?? [];
            foreach ($media as $uid => $m) {
                if (!in_array($uid, $keep, true)) {
                    if (!empty($m['path'])) \Illuminate\Support\Facades\Storage::disk('public')->delete($m['path']);
                    unset($media[$uid]);
                }
            }
            $template->carousel_media = $media ?: null;
        }
        if (array_key_exists('auth_apps', $data)) {
            $template->auth_apps = $data['auth_apps'];
        }
        if (array_key_exists('auth_add_expiry', $data)) {
            $template->auth_add_expiry = (bool) $data['auth_add_expiry'];
        }
        if (array_key_exists('auth_add_security_recommendation', $data)) {
            $template->auth_add_security_recommendation = (bool) $data['auth_add_security_recommendation'];
        }
        if (array_key_exists('auth_zero_tap_terms_accepted', $data)) {
            $template->auth_zero_tap_terms_accepted = (bool) $data['auth_zero_tap_terms_accepted'];
        }
        $template->save();

        return $template->fresh();
    }

    public function delete(int $id, int $companyId): void
    {
        $template = $this->show($id, $companyId);
        $template->delete();
    }

    // ── Header media ───────────────────────────────────────────────────
    public function attachHeaderMedia(int $id, int $companyId, string $handle, string $path, string $url): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $template->update([
            'header_handle'      => $handle,
            'header_sample_path' => $path,
            'header_sample_url'  => $url,
        ]);
        return $template->fresh();
    }

    public function clearHeaderMedia(int $id, int $companyId): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $template->update(['header_handle' => null, 'header_sample_path' => null, 'header_sample_url' => null]);
        return $template->fresh();
    }

    // ── Footer media — local reference only, never sent to Meta ───────────
    public function attachFooterMedia(int $id, int $companyId, ?string $handle, string $path, string $url): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $template->update([
            'footer_media_handle' => $handle,
            'footer_media_path'   => $path,
            'footer_media_url'    => $url,
        ]);
        return $template->fresh();
    }

    public function clearFooterMedia(int $id, int $companyId): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $template->update(['footer_media_handle' => null, 'footer_media_path' => null, 'footer_media_url' => null]);
        return $template->fresh();
    }

    // ── Per-button media — local reference only, never sent to Meta ───────
    // $buttonId is the button's position (index) in the buttons array.
    public function attachButtonMedia(int $id, int $companyId, int $buttonId, ?string $handle, string $path, string $url): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $buttons  = $template->buttons ?? [];

        if (!isset($buttons[$buttonId])) {
            throw new \Exception('Button not found on this template.');
        }

        $buttons[$buttonId]['media_handle'] = $handle;
        $buttons[$buttonId]['media_path']   = $path;
        $buttons[$buttonId]['media_url']    = $url;
        $template->update(['buttons' => $buttons]);

        return $template->fresh();
    }

    public function clearButtonMedia(int $id, int $companyId, int $buttonId): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $buttons  = $template->buttons ?? [];

        if (!isset($buttons[$buttonId])) {
            throw new \Exception('Button not found on this template.');
        }

        unset($buttons[$buttonId]['media_handle'], $buttons[$buttonId]['media_path'], $buttons[$buttonId]['media_url']);
        $template->update(['buttons' => $buttons]);

        return $template->fresh();
    }

    /**
     * Pull a single template's current status from Meta Graph API.
     * (Named distinctly from the controller's bulk syncFromMeta(), which
     * re-syncs every template for the company — a different operation.)
     */
    public function syncSingleFromMeta(int $id, int $companyId): WaTemplate
    {
        $template = $this->show($id, $companyId);
        $company = auth()->user()->company;

        if (!$template->wa_template_id) {
            throw new \Exception('No Meta template ID set. Add wa_template_id first.');
        }

        // $phone =
        //  $template->waPhoneNumber;
        // if (!$phone) {
        //     throw new \Exception('No phone number linked to this template.');
        // }

        // `decrypt_wa_access_token` is an accessor that ALREADY returns the decrypted token (or null).
        // Running decrypt() on it again threw DecryptException -> HTTP 500 on every single-template sync.
        $token = $company->decrypt_wa_access_token;
        if (!$token) {
            throw new \Exception('WhatsApp access token is missing or unreadable — save it again in WhatsApp settings.');
        }

        $response = $this->fetchMetaTemplate($token, $template->wa_template_id);

        if (!$response->successful()) {
            throw new \Exception($response->json('error.message') ?? "Meta returned HTTP {$response->status()} while syncing the template.");
        }

        $template->update($this->attributesFromMeta($response->json(), $template));

        return $template->fresh();
    }

    /** GET one template node, asking for components + validity; retry without validity if Meta rejects the field. */
    private function fetchMetaTemplate(string $token, string $metaId)
    {
        $fields = 'id,name,category,language,status,rejected_reason,components,message_send_ttl_seconds';
        $response = Http::withToken($token)->timeout(10)
            ->get(MetaGraph::templateNodeUrl($metaId), ['fields' => $fields]);

        if ($response->failed()) {
            $response = Http::withToken($token)->timeout(10)
                ->get(MetaGraph::templateNodeUrl($metaId), [
                    'fields' => 'id,name,category,language,status,rejected_reason,components',
                ]);
        }

        return $response;
    }

    /**
     * Map one Meta template node (with `components`) onto our local columns, so a synced
     * template carries everything a locally-created one does: header media, footer, every
     * button type (incl. COPY_CODE), variable samples and the validity period.
     *
     * Pass the existing row to keep a locally uploaded header sample (permanent) in
     * preference to Meta's CDN URL, which is signed and expires.
     */
    public function attributesFromMeta(array $meta, ?WaTemplate $existing = null): array
    {
        $components = collect($meta['components'] ?? []);
        $header = $components->firstWhere('type', 'HEADER');
        $body   = $components->firstWhere('type', 'BODY');
        $footer = $components->firstWhere('type', 'FOOTER');
        $btns   = $components->firstWhere('type', 'BUTTONS');

        $format = strtoupper($header['format'] ?? 'TEXT');
        $attrs = [
            'status'           => strtolower($meta['status'] ?? 'pending'),
            'rejection_reason' => $meta['rejected_reason'] ?? null,
            'body'             => $body['text'] ?? '',
            'body_examples'    => $body['example']['body_text'][0] ?? null,
            'header_format'    => $format,
            'header'           => $format === 'TEXT' ? ($header['text'] ?? null) : null,
            'header_example'   => $format === 'TEXT' ? ($header['example']['header_text'][0] ?? null) : null,
            'footer'           => $footer['text'] ?? null,
            'buttons'          => $btns ? $this->buttonsFromMeta($btns['buttons'] ?? []) : null,
        ];

        // Carousel: cards live in a CAROUSEL component; keep a card's uploaded sample when the card count is unchanged.
        $carousel = $components->firstWhere('type', 'CAROUSEL');
        if ($carousel && !empty($carousel['cards'])) {
            $oldCards = $existing?->template_type === 'carousel' ? ($existing->carousel_cards ?? []) : [];
            $oldMedia = $existing?->carousel_media ?? [];
            $sameCount = count($oldCards) === count($carousel['cards']);
            $cards = []; $media = []; $mediaFormat = null;
            foreach ($carousel['cards'] as $i => $c) {
                $cc   = collect($c['components'] ?? []);
                $ch   = $cc->firstWhere('type', 'HEADER');
                $cb   = $cc->firstWhere('type', 'BODY');
                $cbt  = $cc->firstWhere('type', 'BUTTONS');
                $uid  = $sameCount ? ($oldCards[$i]['uid'] ?? \Illuminate\Support\Str::random(12)) : \Illuminate\Support\Str::random(12);
                $cards[] = ['uid' => $uid, 'body' => $cb['text'] ?? '', 'buttons' => $cbt ? $this->buttonsFromMeta($cbt['buttons'] ?? []) : []];
                $mediaFormat ??= strtoupper($ch['format'] ?? 'IMAGE');
                $metaUrl = $ch['example']['header_handle'][0] ?? null;
                if ($sameCount && !empty($oldMedia[$uid]['path'])) {
                    $media[$uid] = $oldMedia[$uid];          // our own stored copy outlives Meta's expiring CDN link
                } elseif ($metaUrl) {
                    $media[$uid] = ['handle' => null, 'path' => null, 'url' => $metaUrl];
                }
            }
            $attrs['template_type']        = 'carousel';
            $attrs['carousel_media_format'] = $mediaFormat;
            $attrs['carousel_cards']       = $cards;
            $attrs['carousel_media']       = $media ?: null;
            $attrs['buttons']              = null;
        } else {
            $attrs['template_type']  = 'standard';
            $attrs['carousel_cards'] = null;
        }

        $lto = $components->firstWhere('type', 'LIMITED_TIME_OFFER');
        $attrs['lto_text'] = $lto['limited_time_offer']['text'] ?? null;
        if ($lto) $attrs['lto_has_expiration'] = (bool) ($lto['limited_time_offer']['has_expiration'] ?? false);

        if (!empty($meta['category']))  $attrs['category'] = $meta['category'];
        if (!empty($meta['language']))  $attrs['language'] = $meta['language'];
        if (!empty($meta['id']))        $attrs['wa_template_id'] = $meta['id'];
        if (array_key_exists('message_send_ttl_seconds', $meta)) {
            $attrs['message_send_ttl_seconds'] = $meta['message_send_ttl_seconds'];
        }

        // Media header: Meta returns the sample as example.header_handle[0] (a CDN URL).
        if ($format !== 'TEXT') {
            $metaUrl = $header['example']['header_handle'][0] ?? null;
            if ($metaUrl && !($existing && $existing->header_sample_path)) {
                $attrs['header_sample_url'] = $metaUrl;
            }
        } else {
            $attrs['header_sample_url'] = null;
        }

        return $attrs;
    }

    /** Meta button list -> our local button shape (type/text/url/phone_number/example). */
    private function buttonsFromMeta(array $buttons): array
    {
        return collect($buttons)->map(function ($b) {
            $type = strtoupper($b['type'] ?? 'QUICK_REPLY');
            $out  = ['type' => $type, 'text' => $b['text'] ?? ''];
            if (!empty($b['url']))          $out['url'] = $b['url'];
            if (!empty($b['phone_number'])) $out['phone_number'] = $b['phone_number'];
            if (!empty($b['example']))      $out['example'] = is_array($b['example']) ? ($b['example'][0] ?? null) : $b['example'];
            return $out;
        })->values()->all();
    }
}
