<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeadSavedReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Leads → Summary (Basic) and Report (Advanced).
 *
 * `summary()` is a fixed KPI dashboard; `report()` is a filtered, grouped,
 * time-bucketed breakdown that the Advanced page drives and can persist as a
 * `lead_saved_reports` row.
 */
class LeadReportController extends Controller
{
    private const GROUPABLE = ['stage', 'source', 'category', 'agent', 'day'];
    private const DATE_FIELDS = ['created_at', 'assigned_at', 'enrolled_at'];

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    // ── Basic: Leads Summary ─────────────────────────────────────────────────

    public function summary(Request $request): JsonResponse
    {
        $companyId = $this->companyId();
        [$from, $to] = $this->range($request);

        $base = fn () => DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at');
        $inRange = fn () => $base()->whereBetween('created_at', [$from, $to]);

        $total = (int) $base()->count();
        $enrolled = (int) $base()->where('stage', 'enrolled')->count();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'all'          => $total,
                'in_range'     => (int) $inRange()->count(),
                'new_today'    => (int) $base()->whereDate('created_at', today())->count(),
                'new_week'     => (int) $base()->where('created_at', '>=', now()->subDays(7))->count(),
                'new_month'    => (int) $base()->where('created_at', '>=', now()->subDays(30))->count(),
                'unassigned'   => (int) $base()->whereNull('assigned_to')->count(),
                'enrolled'     => $enrolled,
                'conversion_rate' => $total > 0 ? round($enrolled / $total * 100, 1) : 0.0,
            ],
            'by_stage'    => $inRange()->selectRaw('stage, COUNT(*) as total')->groupBy('stage')->pluck('total', 'stage'),
            'by_source'   => $inRange()->selectRaw("COALESCE(source, 'unknown') as source, COUNT(*) as total")->groupBy('source')->pluck('total', 'source'),
            'by_priority' => $inRange()->selectRaw("COALESCE(priority, 'none') as priority, COUNT(*) as total")->groupBy('priority')->pluck('total', 'priority'),
            'by_agent'    => DB::table('leads')
                ->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
                ->where('leads.company_id', $companyId)->whereNull('leads.deleted_at')
                ->whereBetween('leads.created_at', [$from, $to])
                ->selectRaw("COALESCE(users.name, 'Unassigned') as agent, COUNT(*) as total")
                ->groupBy('agent')->orderByDesc('total')->get(),
            'avg_hours_to_assign' => $this->avgHours($companyId, 'created_at', 'assigned_at', $from, $to),
            'avg_hours_to_enroll' => $this->avgHours($companyId, 'created_at', 'enrolled_at', $from, $to),
            'daily' => $this->daily($companyId, 'created_at', $from, $to, []),
        ]);
    }

    // ── Advanced: filtered / grouped Report ──────────────────────────────────

    public function report(Request $request): JsonResponse
    {
        $companyId = $this->companyId();
        $data = $this->validateReport($request);
        [$from, $to] = $this->range($request);
        $dateField = $data['date_field'] ?? 'created_at';
        $groupBy = $data['group_by'] ?? 'stage';

        $q = DB::table('leads')->where('leads.company_id', $companyId)->whereNull('leads.deleted_at')
            ->whereBetween("leads.{$dateField}", [$from, $to]);
        $this->applyFilters($q, $data);

        // grouped breakdown
        if ($groupBy === 'agent') {
            $breakdown = (clone $q)->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
                ->selectRaw("COALESCE(users.name, 'Unassigned') as label, COUNT(*) as total")
                ->groupBy('label')->orderByDesc('total')->get();
        } elseif ($groupBy === 'day') {
            $breakdown = (clone $q)->selectRaw("DATE(leads.{$dateField}) as label, COUNT(*) as total")
                ->groupBy('label')->orderBy('label')->get();
        } else {
            $breakdown = (clone $q)->selectRaw("COALESCE(leads.{$groupBy}, 'unknown') as label, COUNT(*) as total")
                ->groupBy('label')->orderByDesc('total')->get();
        }

        return response()->json([
            'range'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'group_by'  => $groupBy,
            'date_field' => $dateField,
            'total'     => (int) (clone $q)->count(),
            'breakdown' => $breakdown,
            'daily'     => $this->daily($companyId, $dateField, $from, $to, $data),
        ]);
    }

    // ── Advanced: saved report definitions ──────────────────────────────────

    public function savedReports(): JsonResponse
    {
        $rows = LeadSavedReport::where('company_id', $this->companyId())
            ->where(fn ($q) => $q->where('is_shared', true)->orWhere('created_by', auth()->id()))
            ->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function storeSavedReport(Request $request): JsonResponse
    {
        $data = $this->validateReport($request, forSave: true);
        $row = LeadSavedReport::create([
            'company_id' => $this->companyId(),
            'created_by' => auth()->id(),
            'name'       => $data['name'],
            'filters'    => $data['filters'] ?? [],
            'group_by'   => $data['group_by'] ?? 'stage',
            'date_field' => $data['date_field'] ?? 'created_at',
            'is_shared'  => (bool) ($data['is_shared'] ?? false),
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function updateSavedReport(Request $request, int $id): JsonResponse
    {
        $row = LeadSavedReport::where('company_id', $this->companyId())->findOrFail($id);
        $row->update($this->validateReport($request, forSave: true, isUpdate: true));

        return response()->json(['data' => $row]);
    }

    public function destroySavedReport(int $id): JsonResponse
    {
        LeadSavedReport::where('company_id', $this->companyId())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : now()->subDays(30)->startOfDay();
        $to   = $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        return [$from, $to];
    }

    /** @param array<string,mixed> $data */
    private function applyFilters($q, array $data): void
    {
        $f = $data['filters'] ?? $data;
        foreach (['stage', 'source', 'category'] as $col) {
            if (!empty($f[$col])) {
                $q->where("leads.{$col}", $f[$col]);
            }
        }
        if (!empty($f['assigned_to'])) {
            $q->where('leads.assigned_to', $f['assigned_to']);
        }
        if (isset($f['unassigned']) && filter_var($f['unassigned'], FILTER_VALIDATE_BOOL)) {
            $q->whereNull('leads.assigned_to');
        }
    }

    /** @param array<string,mixed> $data */
    private function daily(int $companyId, string $dateField, Carbon $from, Carbon $to, array $data): \Illuminate\Support\Collection
    {
        $q = DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween($dateField, [$from, $to]);
        $this->applyFilters($q, $data);

        return $q->selectRaw("DATE({$dateField}) as date, COUNT(*) as total")
            ->groupBy('date')->orderBy('date')->get();
    }

    private function avgHours(int $companyId, string $start, string $end, Carbon $from, Carbon $to): ?float
    {
        $driver = DB::connection()->getDriverName();
        $expr = $driver === 'sqlite'
            ? "AVG((julianday({$end}) - julianday({$start})) * 24)"
            : "AVG(TIMESTAMPDIFF(MINUTE, {$start}, {$end}) / 60)";

        $v = DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull($end)->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$expr} as v")->value('v');

        return $v === null ? null : round((float) $v, 1);
    }

    /** @return array<string,mixed> */
    private function validateReport(Request $request, bool $forSave = false, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';
        $rules = [
            'group_by'   => ['nullable', Rule::in(self::GROUPABLE)],
            'date_field' => ['nullable', Rule::in(self::DATE_FIELDS)],
            'filters'    => ['nullable', 'array'],
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date'],
        ];
        if ($forSave) {
            $rules['name'] = [$req, 'string', 'max:150'];
            $rules['is_shared'] = ['boolean'];
        }

        return $request->validate($rules);
    }
}
