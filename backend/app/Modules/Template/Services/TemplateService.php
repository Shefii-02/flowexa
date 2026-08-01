<?php

namespace App\Modules\Template\Services;

use App\Models\WaTemplate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class TemplateService
{
    public function list(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return WaTemplate::where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn($q) =>
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('body', 'like', "%{$filters['search']}%")
            )
            ->when($filters['status'] ?? null, fn($q) => $q->where('status', $filters['status']))
            ->when($filters['category'] ?? null, fn($q) => $q->where('category', $filters['category']))
            ->latest()
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    public function show(int $id, int $companyId): WaTemplate
    {
        return WaTemplate::where('id', $id)->where('company_id', $companyId)->firstOrFail();
    }

    public function create(int $companyId, array $data): WaTemplate
    {
        return WaTemplate::create([
            'company_id'          => $companyId,
            'wa_phone_number_id'  => $data['wa_phone_number_id'] ?? null,
            'name'                => $data['name'],
            'wa_template_id'      => $data['wa_template_id'] ?? null,
            'category'            => $data['category'],
            'language'            => $data['language'] ?? 'en',
            'body'                => $data['body'],
            'body_examples'       => $data['body_examples'] ?? null,
            'header_format'       => $data['header_format'] ?? 'TEXT',
            'header'              => $data['header'] ?? null,
            'header_example'      => $data['header_example'] ?? null,
            'footer'              => $data['footer'] ?? null,
            'buttons'             => $data['buttons'] ?? null,
            'status'              => $data['status'] ?? 'draft',
            'rejection_reason'    => $data['rejection_reason'] ?? null,
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
        ], fn($v) => !is_null($v)));

        // Legitimately nullable/emptyable — clearing all body examples or removing all
        // buttons is a valid edit, so these can't go through array_filter above (it would
        // drop an intentional empty array along with genuine nulls).
        if (array_key_exists('body_examples', $data)) {
            $template->body_examples = $data['body_examples'];
        }
        if (array_key_exists('buttons', $data)) {
            $template->buttons = $data['buttons'];
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
    public function attachFooterMedia(int $id, int $companyId, string $handle, string $path, string $url): WaTemplate
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
    public function attachButtonMedia(int $id, int $companyId, int $buttonId, string $handle, string $path, string $url): WaTemplate
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

        if (!$template->wa_template_id) {
            throw new \Exception('No Meta template ID set. Add wa_template_id first.');
        }

        $phone = $template->waPhoneNumber;
        if (!$phone) {
            throw new \Exception('No phone number linked to this template.');
        }

        $response = Http::withToken(decrypt($phone->access_token))
            ->timeout(10)
            ->get("https://graph.facebook.com/v25.0/{$template->wa_template_id}");

        if ($response->successful()) {
            $meta   = $response->json();
            $status = strtolower($meta['status'] ?? 'pending');
            $template->update([
                'status'           => $status,
                'rejection_reason' => $meta['rejected_reason'] ?? null,
            ]);
        }

        return $template->fresh();
    }
}

// namespace App\Modules\Template\Services;

// use App\Models\WaTemplate;
// use Illuminate\Pagination\LengthAwarePaginator;
// use Illuminate\Support\Facades\Http;

// class TemplateService
// {
//     public function list(int $companyId, array $filters = []): LengthAwarePaginator
//     {
//         return WaTemplate::where('company_id', $companyId)
//             ->when($filters['search'] ?? null, fn($q) =>
//                 $q->where('name', 'like', "%{$filters['search']}%")
//                   ->orWhere('body', 'like', "%{$filters['search']}%")
//             )
//             ->when($filters['status'] ?? null, fn($q) => $q->where('status', $filters['status']))
//             ->when($filters['category'] ?? null, fn($q) => $q->where('category', $filters['category']))
//             ->latest()
//             ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
//     }

//     public function show(int $id, int $companyId): WaTemplate
//     {
//         return WaTemplate::where('id', $id)->where('company_id', $companyId)->firstOrFail();
//     }

//     public function create(int $companyId, array $data): WaTemplate
//     {
//         return WaTemplate::create([
//             'company_id'         => $companyId,
//             'wa_phone_number_id' => $data['wa_phone_number_id'] ?? null,
//             'name'               => $data['name'],
//             'wa_template_id'     => $data['wa_template_id'] ?? null,
//             'category'           => $data['category'],
//             'language'           => $data['language'] ?? 'en',
//             'body'               => $data['body'],
//             'header'             => $data['header'] ?? null,
//             'footer'             => $data['footer'] ?? null,
//             'variables'          => $data['variables'] ?? null,
//             'status'             => $data['status'] ?? 'pending',
//         ]);
//     }

//     public function update(int $id, int $companyId, array $data): WaTemplate
//     {
//         $template = $this->show($id, $companyId);
//         $template->update(array_filter([
//             'name'               => $data['name'] ?? null,
//             'wa_template_id'     => $data['wa_template_id'] ?? null,
//             'category'           => $data['category'] ?? null,
//             'language'           => $data['language'] ?? null,
//             'body'               => $data['body'] ?? null,
//             'header'             => $data['header'] ?? null,
//             'footer'             => $data['footer'] ?? null,
//             'variables'          => $data['variables'] ?? null,
//             'status'             => $data['status'] ?? null,
//             'wa_phone_number_id' => $data['wa_phone_number_id'] ?? null,
//         ], fn($v) => !is_null($v)));
//         return $template->fresh();
//     }

//     public function delete(int $id, int $companyId): void
//     {
//         $template = $this->show($id, $companyId);
//         $template->delete();
//     }

//     /**
//      * Pull template status from Meta Graph API.
//      */
//     public function syncFromMeta(int $id, int $companyId): WaTemplate
//     {
//         $template = $this->show($id, $companyId);

//         if (!$template->wa_template_id) {
//             throw new \Exception('No Meta template ID set. Add wa_template_id first.');
//         }

//         // Get access token from phone number
//         $phone = $template->waPhoneNumber;
//         if (!$phone) {
//             throw new \Exception('No phone number linked to this template.');
//         }

//         $response = Http::withToken(decrypt($phone->access_token))
//             ->timeout(10)
//             ->get("https://graph.facebook.com/v25.0/{$template->wa_template_id}");

//         if ($response->successful()) {
//             $meta   = $response->json();
//             $status = strtolower($meta['status'] ?? 'pending');
//             $template->update(['status' => $status]);
//         }

//         return $template->fresh();
//     }
// }
