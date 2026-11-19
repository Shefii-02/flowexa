<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeadActivity;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Calls / messages / meetings staff log on a lead — the raw data behind the daily sales-activity monitor. */
class LeadActivityController extends Controller
{
    public function __construct(private readonly LeadService $leads, private readonly LeadRepositoryInterface $repo) {}

    private function lead(int $id)
    {
        $u = auth()->user();
        return $this->leads->show($id, $u->company_id, $u->id, $u->hasPermission('leads.view_all'));   // 404 / 403 exactly like viewing the lead
    }

    /** GET /leads/{lead}/activities */
    public function index(int $lead): JsonResponse
    {
        $l = $this->lead($lead);
        $rows = LeadActivity::where('lead_id', $l->id)->with('user:id,name')->orderByDesc('happened_at')->orderByDesc('id')->limit(100)->get();
        return response()->json(['data' => $rows->map(fn ($a) => [
            'id' => $a->id, 'kind' => $a->kind, 'outcome' => $a->outcome, 'first' => $a->is_first_contact, 'duration_seconds' => $a->duration_seconds,
            'notes' => $a->notes, 'next_follow_up_at' => $a->next_follow_up_at?->toIso8601String(), 'happened_at' => $a->happened_at->toIso8601String(), 'by' => $a->user?->name,
        ])]);
    }

    /** POST /leads/{lead}/activities — log a call (or message / meeting). */
    public function store(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate([
            'kind'              => ['nullable', 'in:' . implode(',', LeadActivity::KINDS)],
            'outcome'           => ['nullable', 'in:' . implode(',', LeadActivity::OUTCOMES)],
            'duration_seconds'  => ['nullable', 'integer', 'min:0', 'max:86400'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
            'stage'             => ['nullable', 'in:new,contacted,follow_up,enrolled,lost'],
            'happened_at'       => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $l = $this->lead($lead);
        $user = auth()->user();
        $kind = $d['kind'] ?? 'call';

        $activity = DB::transaction(function () use ($l, $user, $d, $kind) {
            \App\Models\Lead::whereKey($l->id)->lockForUpdate()->first();   // two calls logged at once must not both count as the first
            $first = !LeadActivity::where('lead_id', $l->id)->exists();
            $a = LeadActivity::create([
                'company_id' => $l->company_id, 'lead_id' => $l->id, 'user_id' => $user->id, 'kind' => $kind,
                'outcome' => $d['outcome'] ?? null, 'is_first_contact' => $first, 'duration_seconds' => $d['duration_seconds'] ?? null,
                'notes' => $d['notes'] ?? null, 'next_follow_up_at' => $d['next_follow_up_at'] ?? null,
                'happened_at' => isset($d['happened_at']) ? Carbon::parse($d['happened_at']) : now(),
            ]);

            // keep the pipeline in step: an explicit stage wins; otherwise reaching a new lead → Contacted, a booked callback → Follow-up
            $stage = $d['stage'] ?? null;
            if (!$stage && $l->stage === 'new' && in_array($d['outcome'] ?? null, LeadActivity::REACHED, true)) { $stage = 'contacted'; }
            if (!$stage && !empty($d['next_follow_up_at']) && in_array($l->stage, ['new', 'contacted'], true)) { $stage = 'follow_up'; }
            $this->repo->update($l, new UpdateLeadDTO(stage: $stage && $stage !== $l->stage ? $stage : null, followedUpAt: $a->happened_at));
            $this->repo->logEvent($l, $kind . '_logged', ['activity_id' => $a->id, 'outcome' => $a->outcome, 'first' => $first, 'notes' => $a->notes]);
            return $a;
        });

        return response()->json(['message' => ucfirst($kind) . ' logged.', 'data' => ['id' => $activity->id, 'first' => $activity->is_first_contact, 'stage' => $l->fresh()->stage]], 201);
    }

    /** PUT /leads/{lead}/cost — what this lead cost to get (ad spend etc.); feeds cost per converted lead. */
    public function cost(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate(['cost' => ['nullable', 'numeric', 'min:0', 'max:99999999']]);
        $l = $this->lead($lead);
        $l->update(['cost' => $d['cost'] ?? null]);
        return response()->json(['message' => 'Lead cost saved.', 'cost' => $l->fresh()->cost]);
    }
}
