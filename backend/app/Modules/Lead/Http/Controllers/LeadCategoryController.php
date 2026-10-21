<?php
namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeadCategory;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

class LeadCategoryController extends Controller
{
    // GET /lead-categories
    // Supports: ?search=uni&active_only=1&with_count=1
    public function index(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $query = LeadCategory::where('company_id', $cid)
            ->when($request->search, fn($q) =>
                $q->where('name', 'like', '%' . $request->search . '%')
            )
            ->when($request->boolean('active_only', true), fn($q) =>
                $q->where('is_active', true)
            )
            ->orderBy('sort_order')
            ->orderBy('name');

        // Return just names array for simple search dropdowns
        if ($request->boolean('names_only')) {
            return response()->json([
                'categories' => $query->pluck('name'),
            ]);
        }

        // Leads point at a category by its NAME (leads.category) — lead_category_id is never filled in — so count by name.
        $stats = \App\Models\Lead::where('company_id', $cid)->whereNotNull('category')
            ->selectRaw('category, count(*) as leads, count(distinct contact_id) as contacts')->groupBy('category')->get()->keyBy('category');

        return response()->json([
            'categories' => $query->get()->map(function ($c) use ($stats) {
                $c->leads_count    = (int) ($stats[$c->name]->leads ?? 0);
                $c->contacts_count = (int) ($stats[$c->name]->contacts ?? 0);
                return $c;
            })->values(),
        ]);
    }

    // POST /lead-categories
    public function store(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer'],
        ]);

        // Check uniqueness within company
        $exists = LeadCategory::where('company_id', $cid)
            ->where('name', $d['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "Category '{$d['name']}' already exists.",
            ], 422);
        }

        $category = LeadCategory::create([
            'company_id'  => $cid,
            'name'        => $d['name'],
            'color'       => $d['color']       ?? '#1D9E75',
            'description' => $d['description'] ?? null,
            'sort_order'  => $d['sort_order']  ?? LeadCategory::where('company_id', $cid)->max('sort_order') + 1,
            'is_active'   => true,
        ]);

        return response()->json(['category' => $category], 201);
    }

    // PUT /lead-categories/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $category = LeadCategory::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $d = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['nullable', 'integer'],
        ]);

        // Check name uniqueness if changing name
        if (isset($d['name']) && $d['name'] !== $category->name) {
            $exists = LeadCategory::where('company_id', auth()->user()->company_id)
                ->where('name', $d['name'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => "Category '{$d['name']}' already exists."], 422);
            }
        }

        $oldName = $category->name;
        $category->update($d);
        if (isset($d['name']) && $d['name'] !== $oldName) { // leads carry the name, so they follow a rename
            \App\Models\Lead::where('company_id', $category->company_id)->where('category', $oldName)->update(['category' => $d['name']]);
        }

        return response()->json(['category' => $category->fresh()]);
    }

    // DELETE /lead-categories/{id}
    public function destroy(int $id): JsonResponse
    {
        $category = LeadCategory::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $leadCount = \App\Models\Lead::where('company_id', $category->company_id)->where('category', $category->name)->count();
        if ($leadCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$leadCount} lead(s) use this category. Reassign them first.",
            ], 422);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    private function findCategory(int $id): LeadCategory
    {
        return LeadCategory::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }

    // GET /lead-categories/{id}/contacts?search=&page=&per_page=
    // The contacts that have a lead in this category, each with their labels, latest lead, campaigns and last chat.
    public function contacts(Request $request, int $id): JsonResponse
    {
        $cid = auth()->user()->company_id;
        $category = $this->findCategory($id);

        $q = \App\Models\Contact::where('contacts.company_id', $cid)
            ->whereIn('contacts.id', \App\Models\Lead::where('company_id', $cid)->where('category', $category->name)->select('contact_id'))
            ->when(trim((string) $request->search) !== '', function ($q) use ($request) {
                $t = '%' . trim((string) $request->search) . '%';
                $q->where(fn ($w) => $w->where('contacts.name', 'like', $t)->orWhere('contacts.phone', 'like', $t));
            })
            ->with(['labels:id,name,color'])
            ->orderBy('contacts.name');

        $page = $q->paginate(min(max($request->integer('per_page', 25), 5), 100));
        $ids  = $page->getCollection()->pluck('id');

        $leads = \App\Models\Lead::where('company_id', $cid)->where('category', $category->name)->whereIn('contact_id', $ids)
            ->with('assignedTo:id,name')->latest()->get()->groupBy('contact_id');
        $campaigns = \Illuminate\Support\Facades\DB::table('campaign_contacts')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_contacts.campaign_id')
            ->whereIn('campaign_contacts.contact_id', $ids)->whereNull('campaigns.deleted_at')
            ->select('campaign_contacts.contact_id', 'campaigns.id', 'campaigns.name')->distinct()->get()->groupBy('contact_id');
        $lastChat = \App\Models\WaConversation::where('company_id', $cid)->whereIn('contact_id', $ids)
            ->selectRaw('contact_id, max(last_message_at) as at, max(unread_count) as unread')->groupBy('contact_id')->get()->keyBy('contact_id');

        $rows = $page->getCollection()->map(function ($c) use ($leads, $campaigns, $lastChat) {
            $ls = $leads[$c->id] ?? collect(); $latest = $ls->first(); $cs = $campaigns[$c->id] ?? collect();
            return [
                'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone,
                'labels' => $c->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
                'leads_count' => $ls->count(),
                'lead' => $latest ? ['id' => $latest->id, 'stage' => $latest->stage, 'priority' => $latest->priority, 'source' => $latest->source,
                                     'assigned_to' => $latest->assignedTo?->name, 'created_at' => $latest->created_at?->toIso8601String()] : null,
                'campaigns_count' => $cs->count(),
                'campaigns' => $cs->take(3)->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values(),
                'last_chat_at' => isset($lastChat[$c->id]) ? \Illuminate\Support\Carbon::parse($lastChat[$c->id]->at)->toIso8601String() : null,
                'unread' => (int) ($lastChat[$c->id]->unread ?? 0),
            ];
        });

        $stages = \App\Models\Lead::where('company_id', $cid)->where('category', $category->name)->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');

        return response()->json([
            'category' => $category, 'contacts' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'has_more' => $page->hasMorePages(),
            'stages' => $stages, 'leads_count' => (int) $stages->sum(),
        ]);
    }

    // POST /lead-categories/{id}/contacts  { contact_ids: [..] }
    // A contact that already has an open lead has that lead moved into the category; one without gets a new lead.
    public function addContacts(Request $request, int $id): JsonResponse
    {
        $cid = auth()->user()->company_id;
        $category = $this->findCategory($id);
        $d = $request->validate(['contact_ids' => ['required', 'array', 'min:1', 'max:500'], 'contact_ids.*' => ['integer']]);

        $repo = app(\App\Modules\Lead\Repositories\LeadRepository::class);
        $added = 0; $moved = 0; $already = 0;
        foreach (\App\Models\Contact::where('company_id', $cid)->whereIn('id', $d['contact_ids'])->get() as $contact) {
            $open = \App\Models\Lead::where('company_id', $cid)->where('contact_id', $contact->id)->whereNotIn('stage', ['enrolled', 'lost'])->latest()->first();
            if ($open && $open->category === $category->name) { $already++; continue; }
            if ($open) {
                $from = $open->category;
                $open->update(['category' => $category->name]);
                $repo->logEvent($open, 'category_changed', ['from' => $from, 'to' => $category->name]);
                $moved++;
            } else {
                $lead = \App\Models\Lead::create(['company_id' => $cid, 'contact_id' => $contact->id, 'category' => $category->name, 'source' => 'manual', 'stage' => 'new', 'priority' => 'medium']);
                $repo->logEvent($lead, 'lead_created', ['source' => 'manual', 'category' => $category->name]);
                $added++;
            }
        }

        return response()->json(['message' => "{$added} new lead(s), {$moved} moved into “{$category->name}”" . ($already ? ", {$already} already there" : '') . '.',
                                 'added' => $added, 'moved' => $moved, 'already' => $already]);
    }

    // DELETE /lead-categories/{id}/contacts/{contact} — take the contact's leads out of this category (the leads stay)
    public function removeContact(int $id, int $contact): JsonResponse
    {
        $cid = auth()->user()->company_id;
        $category = $this->findCategory($id);
        $repo = app(\App\Modules\Lead\Repositories\LeadRepository::class);
        $n = 0;
        foreach (\App\Models\Lead::where('company_id', $cid)->where('contact_id', $contact)->where('category', $category->name)->get() as $lead) {
            $lead->update(['category' => null]);
            $repo->logEvent($lead, 'category_changed', ['from' => $category->name, 'to' => null]);
            $n++;
        }
        return response()->json(['message' => "Removed from “{$category->name}”.", 'removed' => $n]);
    }

    // POST /lead-categories/assign  { lead_id, category: name|null } — set or clear ONE lead's category (the inbox drawer)
    public function assignLead(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;
        $d = $request->validate(['lead_id' => ['required', 'integer'], 'category' => ['nullable', 'string', 'max:100']]);
        $lead = \App\Models\Lead::where('company_id', $cid)->where('id', $d['lead_id'])->firstOrFail();
        $name = $d['category'] ?? null;
        if ($name && !LeadCategory::where('company_id', $cid)->where('name', $name)->exists()) {
            return response()->json(['message' => "Category '{$name}' does not exist."], 422);
        }
        $from = $lead->category;
        $lead->update(['category' => $name]);
        app(\App\Modules\Lead\Repositories\LeadRepository::class)->logEvent($lead, 'category_changed', ['from' => $from, 'to' => $name]);
        return response()->json(['message' => $name ? "Category set to “{$name}”." : 'Category removed.', 'lead' => ['id' => $lead->id, 'category' => $lead->category]]);
    }

    // POST /lead-categories/reorder
    public function reorder(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'order'              => ['required', 'array'],
            'order.*.id'         => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($d, $cid) {
            foreach ($d['order'] as $item) {
                LeadCategory::where('id', $item['id'])
                    ->where('company_id', $cid)
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }

    // POST /lead-categories/sync-from-nodes
    // Scan all flow nodes for lead_category strings and create missing categories
    public function syncFromNodes(): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $nodeCategories = \App\Models\FlowNode::where('company_id', $cid)
            ->whereNotNull('lead_category')
            ->where('lead_category', '!=', '')
            ->pluck('lead_category')
            ->unique()
            ->values();

        $created = 0;
        foreach ($nodeCategories as $catName) {
            $exists = LeadCategory::where('company_id', $cid)->where('name', $catName)->exists();
            if (!$exists) {
                LeadCategory::create([
                    'company_id' => $cid,
                    'name'       => $catName,
                    'color'      => '#1D9E75',
                    'is_active'  => true,
                    'sort_order' => LeadCategory::where('company_id', $cid)->max('sort_order') + 1,
                ]);
                $created++;
            }
        }

        return response()->json([
            'message'   => "Synced. {$created} new categories created from flow nodes.",
            'synced'    => $nodeCategories->count(),
            'created'   => $created,
        ]);
    }
}
