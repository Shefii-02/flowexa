<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FlowNodeHit;
use App\Models\LeadCategory;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\DTOs\BulkAssignDTO;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Flow clicks" for a lead category: which customers reached the flow nodes tagged with it, with their details,
 * whether they are a lead (created by the click, already one, or not yet), filters, and convert-to-lead.
 * Lead status is read live: a customer counts as a lead while they have a lead in this category.
 */
class LeadCategoryFlowController extends Controller
{
    public function __construct(private readonly LeadRepositoryInterface $leads, private readonly LeadService $leadService) {}

    private function category(int $id): LeadCategory
    {
        return LeadCategory::where('company_id', auth()->user()->company_id)->findOrFail($id);
    }

    /** Hits of this category inside the chosen dates (the status filter is applied separately). */
    private function base(Request $r, LeadCategory $cat): Builder
    {
        return FlowNodeHit::query()
            ->where('flow_node_hits.company_id', $cat->company_id)
            ->where('flow_node_hits.category', $cat->name)
            ->when($r->filled('from'), fn ($q) => $q->where('flow_node_hits.clicked_at', '>=', \Carbon\Carbon::parse($r->from)->startOfDay()))
            ->when($r->filled('to'), fn ($q) => $q->where('flow_node_hits.clicked_at', '<=', \Carbon\Carbon::parse($r->to)->endOfDay()))
            ->when($r->filled('node_id'), fn ($q) => $q->where('flow_node_hits.flow_node_id', (int) $r->node_id))
            ->when($r->filled('handler'), function ($q) use ($r) {
                // handler = a user id, or "none" for leads nobody is handling yet
                $q->whereExists(function ($s) use ($r) {
                    $s->select(DB::raw(1))->from('leads')
                        ->whereColumn('leads.contact_id', 'flow_node_hits.contact_id')
                        ->whereColumn('leads.category', 'flow_node_hits.category')
                        ->whereColumn('leads.company_id', 'flow_node_hits.company_id')
                        ->whereNull('leads.deleted_at')
                        ->when($r->handler === 'none', fn ($w) => $w->whereNull('leads.assigned_to'), fn ($w) => $w->where('leads.assigned_to', (int) $r->handler));
                });
            });
    }

    /** Customers who have a lead in this category right now. */
    private function hasLead(Builder $q, bool $yes): Builder
    {
        return $q->{$yes ? 'whereExists' : 'whereNotExists'}(function ($s) {
            $s->select(DB::raw(1))->from('leads')
                ->whereColumn('leads.contact_id', 'flow_node_hits.contact_id')
                ->whereColumn('leads.category', 'flow_node_hits.category')
                ->whereColumn('leads.company_id', 'flow_node_hits.company_id')
                ->whereNull('leads.deleted_at');
        });
    }

    private function filtered(Request $r, LeadCategory $cat): Builder
    {
        $q = $this->base($r, $cat);
        $status = $r->input('status', 'all');
        if ($status === 'created') $this->hasLead($q, true);
        if ($status === 'not_created') $this->hasLead($q, false);
        if (trim((string) $r->search) !== '') {
            $t = '%' . trim((string) $r->search) . '%';
            // match the name / number saved at click time, or the contact's current details
            $q->where(fn ($w) => $w->where('flow_node_hits.phone', 'like', $t)->orWhere('flow_node_hits.contact_name', 'like', $t)
                ->orWhereIn('flow_node_hits.contact_id', \App\Models\Contact::where('company_id', $cat->company_id)
                    ->where(fn ($c) => $c->where('name', 'like', $t)->orWhere('phone', 'like', $t)->orWhere('email', 'like', $t))->select('id')));
        }
        return $q;
    }

    // GET /lead-categories/{id}/flow-hits?from=&to=&status=all|created|not_created&node_id=&search=&page=&per_page=
    public function index(Request $request, int $id): JsonResponse
    {
        $cat = $this->category($id);

        $total = (clone $this->base($request, $cat))->count();
        $created = $this->hasLead(clone $this->base($request, $cat), true)->count();
        $customers = (clone $this->base($request, $cat))->distinct()->count('flow_node_hits.contact_id');
        $customersCreated = $this->hasLead(clone $this->base($request, $cat), true)->distinct()->count('flow_node_hits.contact_id');

        $byNode = (clone $this->base($request, $cat))
            ->selectRaw('flow_node_hits.flow_node_id, max(flow_node_hits.node_title) as title, count(*) as clicks, count(distinct flow_node_hits.contact_id) as customers')
            ->groupBy('flow_node_hits.flow_node_id')->orderByDesc('clicks')->get();

        $rows = $this->filtered($request, $cat)
            ->leftJoin('contacts', 'contacts.id', '=', 'flow_node_hits.contact_id')
            ->select('flow_node_hits.*', 'contacts.name as c_name', 'contacts.email as c_email', 'contacts.phone as c_phone')
            ->addSelect(DB::raw('(select leads.id from leads where leads.contact_id = flow_node_hits.contact_id and leads.category = flow_node_hits.category and leads.company_id = flow_node_hits.company_id and leads.deleted_at is null order by leads.id desc limit 1) as current_lead_id'))
            ->addSelect(DB::raw('(select leads.stage from leads where leads.contact_id = flow_node_hits.contact_id and leads.category = flow_node_hits.category and leads.company_id = flow_node_hits.company_id and leads.deleted_at is null order by leads.id desc limit 1) as current_lead_stage'))
            ->addSelect(DB::raw('(select users.name from leads left join users on users.id = leads.assigned_to where leads.contact_id = flow_node_hits.contact_id and leads.category = flow_node_hits.category and leads.company_id = flow_node_hits.company_id and leads.deleted_at is null order by leads.id desc limit 1) as current_handler_name'))
            ->addSelect(DB::raw('(select leads.assigned_to from leads where leads.contact_id = flow_node_hits.contact_id and leads.category = flow_node_hits.category and leads.company_id = flow_node_hits.company_id and leads.deleted_at is null order by leads.id desc limit 1) as current_handler_id'))
            ->orderByDesc('flow_node_hits.clicked_at')->orderByDesc('flow_node_hits.id')
            ->paginate(min(100, max(5, (int) $request->input('per_page', 25))));

        return response()->json([
            'summary' => [
                'clicks' => $total, 'customers' => $customers,
                'lead_created' => $created, 'not_created' => $total - $created,
                'customers_lead_created' => $customersCreated, 'customers_not_created' => $customers - $customersCreated,
            ],
            'by_node' => $byNode,
            // everyone who can be given a lead — options for "Assign to…"
            'staff' => \App\Models\User::where('company_id', $cat->company_id)->orderBy('name')->get(['id', 'name']),
            // staff who currently handle a lead in this category — options for the "Handled by" filter
            'handlers' => \App\Models\User::whereIn('id', \App\Models\Lead::where('company_id', $cat->company_id)->where('category', $cat->name)->whereNotNull('assigned_to')->select('assigned_to'))
                ->orderBy('name')->get(['id', 'name']),
            'hits' => collect($rows->items())->map(fn ($h) => [
                'id'           => $h->id,
                'contact_id'   => $h->contact_id,
                'name'         => $h->c_name ?: $h->contact_name,
                'phone'        => $h->c_phone ?: $h->phone,
                'email'        => $h->c_email,
                'node_id'      => $h->flow_node_id,
                'node_title'   => $h->node_title,
                'clicked_at'   => $h->clicked_at?->toIso8601String(),
                'lead_created' => $h->current_lead_id !== null,
                'lead_id'      => $h->current_lead_id,
                'lead_stage'   => $h->current_lead_stage,
                // who is handling the lead (null = a lead nobody has been assigned to yet; also null when there is no lead)
                'handled_by'   => $h->current_handler_name,
                'handler_id'   => $h->current_handler_id,
                // true = this very click created the lead; false with lead_created = the customer already was a lead
                'created_by_click' => (bool) $h->created_lead && $h->lead_id == $h->current_lead_id,
            ])->values(),
            'total'     => $rows->total(),
            'page'      => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    // POST /lead-categories/{id}/flow-hits/convert   { ids: [hitId…] }  or  { all_not_created: true, from, to, node_id, search }
    public function convert(Request $request, int $id): JsonResponse
    {
        $cat = $this->category($id);
        $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer'], 'all_not_created' => ['nullable', 'boolean']]);

        if ($request->boolean('all_not_created')) {
            // everything not yet a lead under the current filters (dates, node, search)
            $hits = $this->filtered($request->merge(['status' => 'not_created']), $cat)->orderByDesc('flow_node_hits.clicked_at')->limit(500)->get();
        } else {
            $hits = FlowNodeHit::where('company_id', $cat->company_id)->where('category', $cat->name)
                ->whereIn('id', (array) $request->input('ids', []))->get();
        }

        $converted = 0; $already = 0; $failed = 0;
        foreach ($hits->groupBy('contact_id') as $contactId => $contactHits) {
            $hit = $contactHits->sortByDesc('clicked_at')->first();

            $existing = $this->leads->findByContact((int) $contactId, $cat->company_id, $cat->name);
            if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
                FlowNodeHit::whereIn('id', $contactHits->pluck('id'))->whereNull('lead_id')->update(['lead_id' => $existing->id]);
                $already++;
                continue;
            }
            try {
                $lead = $this->leads->create($cat->company_id, CreateLeadDTO::fromFlow((int) $contactId, (int) $hit->flow_node_id, $cat->name, $hit->node_title));
                FlowNodeHit::whereIn('id', $contactHits->pluck('id'))->whereNull('lead_id')->update(['lead_id' => $lead->id]);
                $converted++;
            } catch (\Throwable $e) {
                Log::warning("Convert flow hit to lead failed (contact {$contactId}): " . $e->getMessage());
                $failed++;
            }
        }

        $msg = "{$converted} customer(s) converted to leads" . ($already ? ", {$already} already had a lead" : '') . ($failed ? ", {$failed} failed" : '') . '.';
        return response()->json(['message' => $msg, 'converted' => $converted, 'already' => $already, 'failed' => $failed]);
    }

    // POST /lead-categories/{id}/assign-leads
    //   { user_ids: [..], hit_ids?: [..] | lead_ids?: [..] | scope?: "filtered"|"category", only_unassigned?: bool,
    //     + from / to / node_id / search / status / handler for scope "filtered" }
    // Hands the leads to the chosen staff — one person gets them all, several are shared round-robin.
    public function assign(Request $request, int $id): JsonResponse
    {
        $cat = $this->category($id);
        $request->validate([
            'user_ids'        => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*'      => ['integer'],
            'hit_ids'         => ['nullable', 'array', 'max:1000'],
            'hit_ids.*'       => ['integer'],
            'lead_ids'        => ['nullable', 'array', 'max:1000'],
            'lead_ids.*'      => ['integer'],
            'scope'           => ['nullable', 'in:filtered,category'],
            'only_unassigned' => ['nullable', 'boolean'],
        ]);

        // Open leads of this category only; closed ones (enrolled / lost) are left alone.
        $leads = \App\Models\Lead::where('company_id', $cat->company_id)->where('category', $cat->name)
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->when($request->boolean('only_unassigned'), fn ($q) => $q->whereNull('assigned_to'));

        $skippedNoLead = 0;
        if ($request->filled('lead_ids')) {
            $leads->whereIn('id', $request->input('lead_ids'));
        } elseif ($request->filled('hit_ids') || $request->input('scope') === 'filtered') {
            $hitQuery = $request->filled('hit_ids')
                ? FlowNodeHit::where('company_id', $cat->company_id)->where('category', $cat->name)->whereIn('id', $request->input('hit_ids'))
                : $this->filtered($request, $cat);
            $contactIds = $hitQuery->pluck('flow_node_hits.contact_id')->unique()->values();
            $leads->whereIn('contact_id', $contactIds);
            // customers from the selection who are not a lead yet cannot be assigned — convert them first
            $skippedNoLead = $contactIds->count() - (clone $leads)->distinct()->count('contact_id');
        } elseif ($request->input('scope') !== 'category') {
            return response()->json(['message' => 'Choose which leads to assign.'], 422);
        }

        $leadIds = $leads->orderBy('id')->limit(1000)->pluck('id')->all();
        if (!$leadIds) {
            return response()->json(['message' => 'No open leads to assign' . ($request->boolean('only_unassigned') ? ' (all of them already have a handler).' : '.'), 'assigned' => 0, 'skipped_no_lead' => $skippedNoLead], 422);
        }

        $n = $this->leadService->bulkAssign($cat->company_id, new BulkAssignDTO($leadIds, array_values(array_unique(array_map('intval', $request->input('user_ids')))), 'round_robin'));

        return response()->json([
            'message' => "{$n} lead(s) assigned." . ($skippedNoLead ? " {$skippedNoLead} customer(s) are not leads yet, so they were skipped." : ''),
            'assigned' => $n, 'skipped_no_lead' => $skippedNoLead,
        ]);
    }
}
