<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Models\Listing;
use App\Modules\WaChat\Models\AiKnowledgeBase;
use App\Modules\WaChat\Models\AiKnowledgeChunk;
use App\Modules\WaChat\Jobs\GenerateKnowledgeEmbeddings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class KnowledgeBaseController extends Controller
{
    public function index(): JsonResponse
    {
        $items = AiKnowledgeBase::where('company_id', Auth::user()->company_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'description'   => 'nullable|string|max:500',
            'document_type' => 'required|in:text,url,file',
            'raw_content'   => 'required_if:document_type,text|nullable|string',
            'source_url'    => 'required_if:document_type,url|nullable|url',
        ]);

        $companyId = Auth::user()->company_id;

        $kb = AiKnowledgeBase::create(array_merge($data, [
            'company_id' => $companyId,
            'status'     => 'pending',
        ]));

        dispatch(new GenerateKnowledgeEmbeddings($kb->id));

        return response()->json($kb, 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:120',
            'file'  => 'required|file|mimes:txt,pdf,doc,docx|max:5120',
        ]);

        $companyId = Auth::user()->company_id;
        $path      = $request->file('file')->store("knowledge/{$companyId}", 'public');

        $kb = AiKnowledgeBase::create([
            'company_id'    => $companyId,
            'name'          => $request->name,
            'document_type' => 'file',
            'file_path'     => $path,
            'status'        => 'pending',
        ]);

        dispatch(new GenerateKnowledgeEmbeddings($kb->id));

        return response()->json($kb, 201);
    }

    public function show(int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $chunks = AiKnowledgeChunk::where('knowledge_base_id', $id)
            ->select('id', 'chunk_index', 'content', 'created_at')
            ->orderBy('chunk_index')
            ->get();

        return response()->json(['kb' => $kb, 'chunks' => $chunks]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
            'raw_content' => 'sometimes|string',
            'source_url'  => 'nullable|url',
        ]);

        $kb->update($data);

        // Re-generate embeddings if content changed
        if (isset($data['raw_content']) || isset($data['source_url'])) {
            $kb->update(['status' => 'pending']);
            dispatch(new GenerateKnowledgeEmbeddings($kb->id));
        }

        return response()->json($kb);
    }

    public function destroy(int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($kb->file_path) {
            Storage::disk('public')->delete($kb->file_path);
        }

        $kb->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function reprocess(int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $kb->update(['status' => 'pending', 'error_message' => null]);
        dispatch(new GenerateKnowledgeEmbeddings($kb->id));

        return response()->json(['message' => 'Reprocessing started.']);
    }

    // ── Auto-generate KB content from data the company already has ────────────

    /**
     * Turns the company's own active Catalog listings (products/services/properties — see
     * {@see Listing}) into one knowledge base document, re-indexed synchronously so it's
     * immediately searchable. Re-running this replaces the previous version rather than
     * duplicating it, so it can be used as a repeatable "keep the KB in sync with the
     * catalog" action whenever listings change, not just a one-time import.
     */
    public function syncFromCatalog(): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $listings = Listing::where('company_id', $companyId)->active()->orderBy('sort_order')->get();
        if ($listings->isEmpty()) {
            return response()->json(['message' => 'No active catalog listings to sync yet.'], 422);
        }

        $sections = $listings->map(function (Listing $l) {
            $lines = [$l->title];
            if ($l->description) $lines[] = $l->description;
            if ($l->price) {
                $priceLine = number_format((float) $l->price) . ' ' . $l->currency . ($l->price_unit ? " ({$l->price_unit})" : '');
                $lines[] = "Price: {$priceLine}";
            }
            if ($l->location) $lines[] = "Location: {$l->location}";
            foreach ((array) ($l->attributes ?? []) as $key => $value) {
                if ($value === null || $value === '') continue;
                $lines[] = ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . (is_array($value) ? implode(', ', $value) : $value);
            }
            return implode("\n", $lines);
        })->implode("\n\n---\n\n");

        $kb = AiKnowledgeBase::updateOrCreate(
            ['company_id' => $companyId, 'name' => 'Product & Service Catalog'],
            [
                'description'   => 'Auto-generated from your Catalog — re-sync after adding or changing listings.',
                'document_type' => 'text',
                'raw_content'   => $sections,
                'status'        => 'pending',
            ]
        );

        GenerateKnowledgeEmbeddings::dispatchSync($kb->id);

        return response()->json($kb->fresh());
    }

    /**
     * Turns the company's own profile fields into one knowledge base document — the basic
     * "who are we / how do we reach you" facts a customer asks about most often. Only fields
     * the company has actually filled in are included; re-running replaces the previous
     * version so it stays current as the profile changes.
     */
    public function syncFromCompanyDetails(): JsonResponse
    {
        $company = Company::findOrFail(Auth::user()->company_id);

        $lines = ["Company name: {$company->name}"];
        if ($company->website) $lines[] = "Website: {$company->website}";
        if ($company->email)   $lines[] = "Email: {$company->email}";
        if ($company->phone)   $lines[] = "Phone: {$company->phone}";
        if ($company->industry_template) {
            $lines[] = 'Industry: ' . ucfirst(str_replace('_', ' ', $company->industry_template));
        }

        if (count($lines) <= 1) {
            return response()->json([
                'message' => 'Not enough company profile info filled in yet (add a website, email, or phone under company settings first).',
            ], 422);
        }

        $kb = AiKnowledgeBase::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Company Details'],
            [
                'description'   => 'Auto-generated from your company profile — re-sync after updating company settings.',
                'document_type' => 'text',
                'raw_content'   => implode("\n", $lines),
                'status'        => 'pending',
            ]
        );

        GenerateKnowledgeEmbeddings::dispatchSync($kb->id);

        return response()->json($kb->fresh());
    }

    /**
     * Turns every active Flow Builder's menu tree into one knowledge base document per flow, so the AI agent
     * knows what the bot menus say (replies, options, buttons, links) and can answer the same way. Walks each
     * flow node by node under its menu path; only customer-facing text is included (never API URLs or headers).
     * Re-running replaces the previous version of each flow's document, and documents for flows that no longer
     * exist or are inactive are removed.
     */
    public function syncFromFlowBuilder(): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $flows = FlowBuilder::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get();
        if ($flows->isEmpty()) {
            return response()->json(['message' => 'No active flow builders to sync yet.'], 422);
        }

        $kbNames = AiKnowledgeBase::where('company_id', $companyId)->pluck('name', 'id');
        $synced  = [];

        foreach ($flows as $flow) {
            $nodes = FlowNode::where('company_id', $companyId)->where('flow_builder_id', $flow->id)
                ->where('is_active', true)->orderBy('sort_order')->get()->groupBy('parent_id');

            $sections = [];
            $walk = function (?int $parentId, array $path) use (&$walk, &$sections, $nodes, $kbNames) {
                foreach ($nodes->get($parentId, collect()) as $node) {
                    $here  = [...$path, trim((string) $node->title) ?: 'Option'];
                    $lines = ['Menu: ' . implode(' > ', $here)];

                    $texts = collect((array) $node->multi_messages)
                        ->map(fn ($b) => is_array($b) ? trim((string) ($b['content'] ?? $b['text'] ?? $b['caption'] ?? '')) : '')
                        ->filter();
                    if ($node->message)             $lines[] = trim($node->message);
                    foreach ($texts as $t)          $lines[] = $t;
                    if ($node->option_description)  $lines[] = 'About this option: ' . trim($node->option_description);
                    if ($node->media_caption)       $lines[] = trim($node->media_caption);
                    if ($node->location_name || $node->location_address) {
                        $lines[] = 'Location: ' . trim($node->location_name . ' ' . $node->location_address);
                    }
                    foreach ((array) $node->action_buttons as $btn) {
                        if (!is_array($btn)) continue;
                        $label = trim((string) ($btn['title'] ?? $btn['text'] ?? $btn['label'] ?? ''));
                        $url   = trim((string) ($btn['url'] ?? $btn['phone_number'] ?? $btn['phone'] ?? ''));
                        if ($label !== '' || $url !== '') $lines[] = 'Button: ' . trim($label . ($url !== '' ? " ({$url})" : ''));
                    }
                    $kbs = collect((array) $node->ai_kb_ids)->map(fn ($id) => $kbNames[$id] ?? null)->filter();
                    if ($kbs->isNotEmpty()) $lines[] = 'Answered from knowledge base: ' . $kbs->implode(', ');

                    if (count($lines) > 1) $sections[] = implode("\n", $lines);
                    $walk($node->id, $here);
                }
            };
            $walk(null, []);

            if ($sections === []) continue;

            $name = 'Flow Builder: ' . $flow->name;
            $kb = AiKnowledgeBase::updateOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                [
                    'description'   => 'Auto-generated from your Flow Builder menus — re-sync after changing the flow.',
                    'document_type' => 'text',
                    'raw_content'   => ($flow->description ? "{$flow->description}\n\n" : '') . implode("\n\n---\n\n", $sections),
                    'status'        => 'pending',
                ]
            );
            GenerateKnowledgeEmbeddings::dispatchSync($kb->id);
            $synced[] = $kb->id;
        }

        if ($synced === []) {
            return response()->json(['message' => 'The active flow builders have no node content to sync yet.'], 422);
        }

        // drop documents of flows that were deleted / switched off or emptied
        AiKnowledgeBase::where('company_id', $companyId)->where('name', 'like', 'Flow Builder: %')
            ->whereNotIn('id', $synced)->get()->each(fn ($kb) => $kb->delete());

        return response()->json(['synced' => count($synced), 'knowledge_bases' => AiKnowledgeBase::whereIn('id', $synced)->get()]);
    }
}
