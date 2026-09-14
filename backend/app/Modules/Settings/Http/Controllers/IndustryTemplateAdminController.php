<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IndustryTemplate;
use App\Modules\Catalog\IndustryTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SuperAdmin CRUD over the industry/business-type catalog templates — what a Catalog
 * listing looks like for this vertical (attribute_schema), what qualifies a lead
 * (qualification_fields), and the guided question order (question_flow). This is the
 * Catalog/matching side; the conversational-agent side is the sibling
 * AgentPlaybookTemplateAdminController. A company picks one of these by its `key` as its
 * `industry_template` at signup (see CompanyStarterKit) and it drives the widget's default
 * industry too (see WidgetController::store()).
 */
class IndustryTemplateAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            IndustryTemplate::orderBy('sort_order')->get()
        );
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(IndustryTemplate::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $template = IndustryTemplate::create($this->validated($request, true));
        IndustryTemplates::forgetCache();

        return response()->json(['message' => 'Industry template created.', 'data' => $template], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = IndustryTemplate::findOrFail($id);
        $template->update($this->validated($request, false, $id));
        IndustryTemplates::forgetCache();

        return response()->json(['message' => 'Industry template saved.', 'data' => $template->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $template = IndustryTemplate::findOrFail($id);

        if ($template->key === 'generic') {
            return response()->json(['message' => 'The "generic" template is the fallback for every other business type and can\'t be deleted.'], 422);
        }

        $template->delete();
        IndustryTemplates::forgetCache();

        return response()->json(['message' => 'Industry template deleted.']);
    }

    private function validated(Request $request, bool $creating, ?int $ignoreId = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $keyUnique = 'unique:industry_templates,key' . ($ignoreId ? ",{$ignoreId}" : '');

        return $request->validate([
            'key'          => [$req, 'string', 'max:60', 'alpha_dash', $keyUnique],
            'name'         => [$req, 'string', 'max:120'],
            'listing_type' => ['sometimes', 'string', 'max:60'],
            'is_active'    => ['sometimes', 'boolean'],
            'sort_order'   => ['sometimes', 'integer'],
            'agent_prompt' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'lead_source'  => ['sometimes', 'nullable', 'string', 'max:60'],

            'attribute_schema'              => ['sometimes', 'array'],
            'attribute_schema.*.key'        => ['required_with:attribute_schema', 'string', 'max:60'],
            'attribute_schema.*.label'      => ['required_with:attribute_schema', 'string', 'max:120'],
            'attribute_schema.*.type'       => ['nullable', 'string', 'in:text,number,boolean,enum'],
            'attribute_schema.*.options'    => ['nullable', 'array'],
            'attribute_schema.*.matchable'  => ['nullable', 'boolean'],
            'attribute_schema.*.tolerance'  => ['nullable', 'numeric'],

            'qualification_fields'             => ['sometimes', 'array'],
            'qualification_fields.*.key'       => ['required_with:qualification_fields', 'string', 'max:60'],
            'qualification_fields.*.label'     => ['required_with:qualification_fields', 'string', 'max:120'],
            'qualification_fields.*.type'      => ['nullable', 'string', 'in:text,number,phone,boolean'],
            'qualification_fields.*.required'  => ['nullable', 'boolean'],

            'question_flow'   => ['sometimes', 'array'],
            'question_flow.*' => ['string', 'max:500'],
        ]);
    }
}
