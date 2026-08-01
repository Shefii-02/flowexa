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
            'header_handle'       => $data['header_handle'] ?? null,
            'header_sample_path'  => $data['header_sample_path'] ?? null,
            'header_sample_url'   => $data['header_sample_url'] ?? null,
            'header_example'      => $data['header_example'] ?? null,
            'footer'              => $data['footer'] ?? null,
            'buttons'             => $data['buttons'] ?? null,
            'status'              => $data['status'] ?? 'pending',
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
            'header_handle'       => $data['header_handle'] ?? null,
            'header_sample_path'  => $data['header_sample_path'] ?? null,
            'header_sample_url'   => $data['header_sample_url'] ?? null,
            'header_example'      => $data['header_example'] ?? null,
            'footer'              => $data['footer'] ?? null,
            'status'              => $data['status'] ?? null,
            'wa_phone_number_id'  => $data['wa_phone_number_id'] ?? null,
            'rejection_reason'    => $data['rejection_reason'] ?? null,
        ], fn($v) => !is_null($v)));

        // These two are legitimately nullable/emptyable (clearing all body examples or
        // removing all buttons is a valid edit), so they can't go through array_filter
        // above — array_filter would drop an intentional empty array along with nulls.
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

    /**
     * Pull a single template's current status from Meta Graph API.
     * Renamed from syncFromMeta() to avoid colliding with
     * TemplateController::syncFromMeta(), which does a bulk sync of
     * every template for the company — a different operation entirely.
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
