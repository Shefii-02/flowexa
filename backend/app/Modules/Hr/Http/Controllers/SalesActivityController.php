<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Hr\Models\SalesActivityTarget;
use App\Modules\Hr\Support\SalesActivityMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** HR / CRM → Sales activity: each salesperson's calls, follow-ups and conversions per day and month, against targets. */
class SalesActivityController extends Controller
{
    public function __construct(private readonly SalesActivityMetrics $metrics) {}

    private function companyId(): int { return (int) auth()->user()->company_id; }

    /** Team leader or higher: may see everyone's numbers. */
    private function canMonitor(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->isSuperAdmin() || $u->hasPermission('hr.sales_activity.view');
    }

    private function canSetTargets(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->isSuperAdmin() || $u->hasPermission('hr.sales_activity.manage');
    }

    private function day(Request $request): Carbon
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        return $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::now();
    }

    /** GET /hr/sales-activity/team?date=YYYY-MM-DD — everyone, for the day and the month it falls in. */
    public function team(Request $request): JsonResponse
    {
        abort_unless($this->canMonitor(), 403, 'Only team leaders and managers can see the team.');
        return response()->json($this->metrics->team($this->companyId(), $this->day($request)));
    }

    /** GET /hr/sales-activity/staff/{id}?date= — one person: numbers + the day's activity timeline + month trend. */
    public function staff(Request $request, int $id): JsonResponse
    {
        abort_unless($this->canMonitor() || $id === (int) auth()->id(), 403, 'Not allowed.');
        abort_unless(User::where('company_id', $this->companyId())->whereKey($id)->exists(), 404, 'Employee not found.');
        return response()->json($this->metrics->staffDetail($this->companyId(), $id, $this->day($request)));
    }

    /** GET /hr/sales-activity/me — the caller's own day + month. */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->metrics->staffDetail($this->companyId(), (int) auth()->id(), $this->day($request)));
    }

    /** GET /hr/sales-activity/targets — the company default + every per-employee override. */
    public function targets(): JsonResponse
    {
        abort_unless($this->canMonitor(), 403, 'Not allowed.');
        $rows = SalesActivityTarget::where('company_id', $this->companyId())->get();
        return response()->json(['default' => $rows->first(fn ($r) => $r->user_id === null) ?? SalesActivityTarget::defaultFor($this->companyId()), 'staff' => $rows->filter(fn ($r) => $r->user_id !== null)->values()]);
    }

    /** PUT /hr/sales-activity/targets { user_id?: null|id, daily_new_lead_calls, … } — null user = the company default. 0 on a person = use the default. */
    public function saveTargets(Request $request): JsonResponse
    {
        abort_unless($this->canSetTargets(), 403, 'Only managers can set targets.');
        $cid = $this->companyId();
        $d = $request->validate([
            'user_id'                 => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', $cid)],
            'daily_new_lead_calls'    => ['nullable', 'integer', 'min:0', 'max:10000'],
            'daily_follow_up_calls'   => ['nullable', 'integer', 'min:0', 'max:10000'],
            'monthly_calls'           => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'monthly_conversions'     => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_cost_per_conversion' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'default_lead_cost'       => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        $row = SalesActivityTarget::firstOrNew(['company_id' => $cid, 'user_id' => $d['user_id'] ?? null]);
        foreach (SalesActivityTarget::FIELDS as $f) { $row->{$f} = $d[$f] ?? 0; }
        if (($d['user_id'] ?? null) === null) { $row->default_lead_cost = $d['default_lead_cost'] ?? 0; }   // only meaningful on the default row
        $row->save();
        return response()->json(['message' => 'Targets saved.', 'data' => $row]);
    }

    /** DELETE /hr/sales-activity/targets/{userId} — drop a person's override (back to the default). */
    public function resetTargets(int $userId): JsonResponse
    {
        abort_unless($this->canSetTargets(), 403, 'Only managers can set targets.');
        SalesActivityTarget::where('company_id', $this->companyId())->where('user_id', $userId)->delete();
        return response()->json(['message' => 'Back to the default targets.']);
    }
}
