<?php

namespace App\Modules\Lead\Services;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadEvent;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Per-staff lead pipeline + routing details for the "Leads by staff" pages. */
class StaffLeadInsights
{
    private const STAGES = ['new', 'contacted', 'follow_up', 'enrolled', 'lost'];

    /** @param int[]|null $onlyIds */
    public function overview(int $companyId, ?array $onlyIds = null): Collection
    {
        $staff = User::where('company_id', $companyId)->where('is_active', true)
            ->when($onlyIds, fn ($q) => $q->whereIn('id', $onlyIds))
            ->with('role:id,name')
            ->orderBy('name')->get();
        $ids = $staff->pluck('id')->all();

        $byStage = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, stage, count(*) c')->groupBy('assigned_to', 'stage')->get()->groupBy('assigned_to');
        $today = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->where('assigned_at', '>=', now()->startOfDay())
            ->selectRaw('assigned_to, count(*) c')->groupBy('assigned_to')->pluck('c', 'assigned_to');
        $week = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->where('assigned_at', '>=', now()->subDays(7))
            ->selectRaw('assigned_to, count(*) c')->groupBy('assigned_to')->pluck('c', 'assigned_to');
        $high = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->where('priority', 'high')->whereNotIn('stage', ['enrolled', 'lost'])
            ->selectRaw('assigned_to, count(*) c')->groupBy('assigned_to')->pluck('c', 'assigned_to');

        $methods = LeadAssignment::where('company_id', $companyId)->whereIn('staff_id', $ids)->whereNotNull('assignment_method')
            ->selectRaw('staff_id, assignment_method m, count(*) c')->groupBy('staff_id', 'assignment_method')->get()->groupBy('staff_id');
        $sla = LeadAssignment::where('company_id', $companyId)->whereIn('staff_id', $ids)->where('sla_breached', true)
            ->selectRaw('staff_id, count(*) c')->groupBy('staff_id')->pluck('c', 'staff_id');
        $transferredAway = LeadAssignment::where('company_id', $companyId)->whereIn('transferred_from', $ids)
            ->selectRaw('transferred_from s, count(*) c')->groupBy('transferred_from')->pluck('c', 's');
        $replies = LeadAssignmentNotification::whereIn('staff_id', $ids)->whereIn('response', ['declined', 'no_response'])
            ->selectRaw('staff_id, response, count(*) c')->groupBy('staff_id', 'response')->get()->groupBy('staff_id');
        $avgAccept = LeadAssignment::where('company_id', $companyId)->whereIn('staff_id', $ids)->whereNotNull('accepted_at')
            ->selectRaw('staff_id, avg(TIMESTAMPDIFF(MINUTE, created_at, accepted_at)) m')->groupBy('staff_id')->pluck('m', 'staff_id');
        $lastAssigned = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, max(assigned_at) t')->groupBy('assigned_to')->pluck('t', 'assigned_to');
        $sources = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, coalesce(nullif(source, ""), "unknown") k, count(*) c')->groupBy('assigned_to', 'k')->get()->groupBy('assigned_to');
        $cats = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('assigned_to, category k, count(*) c')->groupBy('assigned_to', 'category')->get()->groupBy('assigned_to');
        $avail = StaffAvailability::whereIn('staff_id', $ids)->get()->keyBy('staff_id');

        $top = fn ($rows) => collect($rows)->sortByDesc('c')->take(5)->map(fn ($r) => ['name' => $r->k, 'count' => (int) $r->c])->values();

        return $staff->map(function (User $u) use ($byStage, $today, $week, $high, $methods, $sla, $transferredAway, $replies, $avgAccept, $lastAssigned, $sources, $cats, $avail, $top) {
            $stage = collect($byStage->get($u->id, []))->pluck('c', 'stage');
            $leads = ['total' => (int) $stage->sum()];
            foreach (self::STAGES as $s) { $leads[$s] = (int) ($stage[$s] ?? 0); }
            $leads['active'] = $leads['total'] - $leads['enrolled'] - $leads['lost'];
            $closed = $leads['enrolled'] + $leads['lost'];
            $a = $avail->get($u->id);
            $r = collect($replies->get($u->id, []))->pluck('c', 'response');

            return [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
                'department' => $u->department, 'role' => $u->role?->name, 'avatar' => $u->avatar,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'max_leads' => (int) $u->max_leads,
                'availability' => [
                    'status' => $a?->status ?? 'offline', 'is_available' => (bool) ($a?->is_available),
                    'last_seen_at' => $a?->last_seen_at?->toIso8601String(),
                    'open_leads' => (int) ($a?->current_leads_count ?? 0),
                ],
                'leads' => $leads,
                'conversion_rate' => $leads['total'] > 0 ? round($leads['enrolled'] / $leads['total'] * 100, 1) : 0,
                'close_rate' => $closed > 0 ? round($leads['enrolled'] / $closed * 100, 1) : 0,
                'high_priority_open' => (int) ($high[$u->id] ?? 0),
                'assigned_today' => (int) ($today[$u->id] ?? 0),
                'assigned_7d' => (int) ($week[$u->id] ?? 0),
                'last_assigned_at' => $lastAssigned[$u->id] ?? null,
                'routing' => [
                    'by_method' => collect($methods->get($u->id, []))->pluck('c', 'm')->map(fn ($c) => (int) $c),
                    'sla_breached' => (int) ($sla[$u->id] ?? 0),
                    'transferred_away' => (int) ($transferredAway[$u->id] ?? 0),
                    'declined' => (int) ($r['declined'] ?? 0),
                    'no_response' => (int) ($r['no_response'] ?? 0),
                    'avg_accept_minutes' => isset($avgAccept[$u->id]) ? round((float) $avgAccept[$u->id], 1) : null,
                ],
                'top_sources' => $top($sources->get($u->id, [])),
                'top_categories' => $top($cats->get($u->id, [])),
            ];
        })->values();
    }

    /** Overview row + the latest assignments and activity for one staff member. */
    public function detail(int $companyId, int $staffId): ?array
    {
        $row = $this->overview($companyId, [$staffId])->first();
        if (!$row) { return null; }

        $row['recent_assignments'] = LeadAssignment::where('company_id', $companyId)->where('staff_id', $staffId)
            ->with(['contact:id,name,phone', 'lead:id,source,category,origin_label'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'lead_id' => $a->lead_id, 'status' => $a->status,
                'method' => $a->assignment_method, 'mode' => $a->assignment_type,
                'contact' => $a->contact?->name ?: $a->contact?->phone,
                'source' => $a->lead?->source, 'category' => $a->lead?->category,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        $row['recent_activity'] = LeadEvent::where('company_id', $companyId)
            ->where(fn ($q) => $q->where('user_id', $staffId)->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $staffId)))
            ->with(['lead:id,contact_id', 'lead.contact:id,name,phone'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'event' => $e->event, 'payload' => $e->payload, 'lead_id' => $e->lead_id,
                'contact' => $e->lead?->contact?->name ?: $e->lead?->contact?->phone,
                'created_at' => $e->created_at->toIso8601String(),
            ]);

        return $row;
    }
}
