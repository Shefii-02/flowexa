<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Verify (approve) converted and closed leads — a checking step for whoever holds `leads.verify`.
 * Filter by outcome, staff member and a date range (when the lead was converted / closed), then approve many at once.
 */
class LeadVerificationController extends Controller
{
    /** "When did it close?" — the conversion date for converted leads, the closing date for lost ones. */
    private const CLOSED_AT = "CASE WHEN leads.stage = 'enrolled' THEN leads.enrolled_at ELSE leads.lost_at END";

    private function filters(Request $request): array
    {
        return $request->validate([
            'outcome'    => ['nullable', 'in:all,converted,closed'],
            'status'     => ['nullable', 'in:all,pending,verified'],
            'staff_id'   => ['nullable', 'integer'],
            'from'       => ['nullable', 'date_format:Y-m-d'],
            'to'         => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search'     => ['nullable', 'string', 'max:100'],
            'per_page'   => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
    }

    /** Converted / closed leads of this company that match the filters (status handled separately so the summary can count both). */
    private function base(array $f)
    {
        $outcome = $f['outcome'] ?? 'all';
        return Lead::where('leads.company_id', auth()->user()->company_id)
            ->whereIn('leads.stage', $outcome === 'converted' ? ['enrolled'] : ($outcome === 'closed' ? ['lost'] : ['enrolled', 'lost']))
            ->when($f['staff_id'] ?? null, fn ($q, $s) => $q->where('leads.assigned_to', $s))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereRaw(self::CLOSED_AT . ' >= ?', [Carbon::parse($d)->startOfDay()]))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereRaw(self::CLOSED_AT . ' <= ?', [Carbon::parse($d)->endOfDay()]))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")));
    }

    private function withStatus($q, ?string $status)
    {
        return match ($status) {
            'pending'  => $q->whereNull('leads.verified_at'),
            'verified' => $q->whereNotNull('leads.verified_at'),
            default    => $q,
        };
    }

    /** GET /leads/verification */
    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $base = $this->base($f);

        $summary = [
            'pending'   => (clone $base)->whereNull('leads.verified_at')->count(),
            'verified'  => (clone $base)->whereNotNull('leads.verified_at')->count(),
            'converted' => (clone $base)->where('leads.stage', 'enrolled')->count(),
            'closed'    => (clone $base)->where('leads.stage', 'lost')->count(),
        ];

        $page = $this->withStatus($base, $f['status'] ?? 'pending')
            ->with(['contact:id,name,phone', 'assignedTo:id,name', 'verifiedBy:id,name'])
            ->orderByRaw(self::CLOSED_AT . ' DESC')->orderByDesc('leads.id')
            ->paginate((int) ($f['per_page'] ?? 25));

        $page->getCollection()->transform(fn (Lead $l) => [
            'id' => $l->id, 'contact' => $l->contact?->name ?: $l->contact?->phone, 'phone' => $l->contact?->phone,
            'outcome' => $l->stage === 'enrolled' ? 'converted' : 'closed', 'stage' => $l->stage,
            'closed_at' => ($l->stage === 'enrolled' ? $l->enrolled_at : $l->lost_at)?->toIso8601String(),
            'staff' => $l->assignedTo?->name, 'staff_id' => $l->assigned_to,
            'category' => $l->category, 'source' => $l->source, 'sale_value' => $l->sale_value, 'cost' => $l->cost,
            'verified_at' => $l->verified_at?->toIso8601String(), 'verified_by' => $l->verifiedBy?->name,
        ]);

        return response()->json($page->toArray() + ['summary' => $summary]);
    }

    /** POST /leads/verification/approve  { lead_ids: [] }  or  { all_matching: true, ...the same filters } */
    public function approve(Request $request): JsonResponse
    {
        return $this->mark($request, true);
    }

    /** POST /leads/verification/revoke — take an approval back. */
    public function revoke(Request $request): JsonResponse
    {
        return $this->mark($request, false);
    }

    private function mark(Request $request, bool $approve): JsonResponse
    {
        $d = $request->validate([
            'lead_ids'     => ['nullable', 'array', 'max:5000'],
            'lead_ids.*'   => ['integer'],
            'all_matching' => ['nullable', 'boolean'],
        ]);
        if (empty($d['lead_ids']) && empty($d['all_matching'])) {
            return response()->json(['message' => 'Select at least one lead.', 'errors' => ['lead_ids' => ['Select at least one lead.']]], 422);
        }

        $q = !empty($d['all_matching'])
            ? $this->base($this->filters($request))
            : Lead::where('leads.company_id', auth()->user()->company_id)->whereIn('leads.stage', ['enrolled', 'lost'])->whereIn('leads.id', $d['lead_ids']);
        $q = $approve ? $q->whereNull('leads.verified_at') : $q->whereNotNull('leads.verified_at');

        $me = auth()->id();
        $ids = $q->limit(5000)->pluck('leads.id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            Lead::whereIn('id', $chunk)->update($approve ? ['verified_at' => now(), 'verified_by' => $me] : ['verified_at' => null, 'verified_by' => null]);
            LeadEvent::insert(array_map(fn ($id) => [
                'lead_id' => $id, 'company_id' => auth()->user()->company_id, 'user_id' => $me,
                'event' => $approve ? 'lead_verified' : 'lead_verification_revoked', 'payload' => json_encode(['by' => $me]),
                'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }

        $n = count($ids);
        return response()->json(['count' => $n, 'message' => $approve ? "{$n} lead(s) verified." : "{$n} approval(s) removed."]);
    }
}
