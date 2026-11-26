<?php

namespace App\Modules\Hr\Support;

use App\Modules\Hr\Models\HrStaffProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Salary revisions (with their activity log), monthly sales targets and lead-category targets / incentive % for one staff member.
 * Everything that reads a salary or a target for a given month goes through here so the month rules live in one place:
 *   salary  : the revision with the latest effective_from not after that month, else the staff profile's own value
 *   target  : that month's own row, else the common (every-month) row, else the profile's legacy monthly_target
 */
class StaffCompensation
{
    private function ym(?string $m): string { return preg_match('/^\d{4}-\d{2}$/', (string) $m) ? $m : now()->format('Y-m'); }

    // ── salary ─────────────────────────────────────────────────────────────────

    /** @return array{monthly_salary: ?float, hourly_rate: ?float} what applies in $month (a Carbon in that month). */
    public function salaryFor(HrStaffProfile $profile, Carbon $month): array
    {
        $row = DB::table('hr_salary_history')->where('company_id', $profile->company_id)->where('user_id', $profile->user_id)
            ->where('effective_from', '<=', $month->copy()->endOfMonth()->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->first();

        if ($row) {
            return ['monthly_salary' => $row->monthly_salary !== null ? (float) $row->monthly_salary : null, 'hourly_rate' => $row->hourly_rate !== null ? (float) $row->hourly_rate : null];
        }

        // Before the first recorded change: what the salary was BEFORE it (so an old month never picks up a later raise).
        $first = DB::table('hr_salary_history')->where('company_id', $profile->company_id)->where('user_id', $profile->user_id)->orderBy('effective_from')->orderBy('id')->first();
        if ($first) {
            return ['monthly_salary' => $first->prev_monthly_salary !== null ? (float) $first->prev_monthly_salary : null, 'hourly_rate' => $first->prev_hourly_rate !== null ? (float) $first->prev_hourly_rate : null];
        }

        return ['monthly_salary' => $profile->monthly_salary, 'hourly_rate' => $profile->hourly_rate];
    }

    /**
     * Record a salary change starting in $effectiveMonth (YYYY-MM). Does nothing (returns null) when neither value actually changes.
     * The profile keeps the values that apply today, so everything reading profile->monthly_salary stays right.
     */
    public function setSalary(int $companyId, int $userId, ?float $monthly, ?float $hourly, ?string $effectiveMonth, ?int $by, ?string $note = null, string $source = 'staff'): ?int
    {
        $profile = HrStaffProfile::forUser($companyId, $userId);
        $month = $this->ym($effectiveMonth);
        $at = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();

        $cur = $this->salaryFor($profile, $at);
        $same = fn ($a, $b) => ($a === null && $b === null) || ($a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005);
        if ($same($cur['monthly_salary'], $monthly) && $same($cur['hourly_rate'], $hourly)) { return null; }

        $id = DB::table('hr_salary_history')->insertGetId([
            'company_id' => $companyId, 'user_id' => $userId, 'effective_from' => $at->toDateString(),
            'monthly_salary' => $monthly, 'hourly_rate' => $hourly,
            'prev_monthly_salary' => $cur['monthly_salary'], 'prev_hourly_rate' => $cur['hourly_rate'],
            'changed_by' => $by, 'note' => $note, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = $this->salaryFor($profile->fresh(), now());
        $profile->update(['monthly_salary' => $now['monthly_salary'], 'hourly_rate' => $now['hourly_rate']]);

        return $id;
    }

    public function salaryHistory(int $companyId, int $userId): array
    {
        return DB::table('hr_salary_history as h')->leftJoin('users as u', 'u.id', '=', 'h.changed_by')
            ->where('h.company_id', $companyId)->where('h.user_id', $userId)->orderByDesc('h.id')->limit(100)
            ->get(['h.id', 'h.effective_from', 'h.monthly_salary', 'h.hourly_rate', 'h.prev_monthly_salary', 'h.prev_hourly_rate', 'h.note', 'h.source', 'h.created_at', 'u.name as changed_by'])
            ->all();
    }

    // ── monthly target ─────────────────────────────────────────────────────────

    public function monthlyTarget(int $companyId, int $userId, ?string $month = null, ?HrStaffProfile $profile = null): float
    {
        $month = $this->ym($month);
        $rows = DB::table('hr_staff_targets')->where('company_id', $companyId)->where('user_id', $userId)
            ->where(fn ($q) => $q->where('month', $month)->orWhereNull('month'))->get();
        $own = $rows->firstWhere('month', $month);
        $common = $rows->first(fn ($r) => $r->month === null);
        if ($own) { return (float) $own->monthly_target; }
        if ($common) { return (float) $common->monthly_target; }

        return (float) (($profile ?? HrStaffProfile::where('company_id', $companyId)->where('user_id', $userId)->first())?->monthly_target ?? 0);
    }

    /** $month null = the common (every-month) target. */
    public function setMonthlyTarget(int $companyId, int $userId, ?string $month, float $amount): void
    {
        $month = $month === null ? null : $this->ym($month);
        $q = DB::table('hr_staff_targets')->where('user_id', $userId);
        $month === null ? $q->whereNull('month') : $q->where('month', $month);
        if ($q->exists()) { $q->update(['monthly_target' => $amount, 'updated_at' => now()]); }
        else { DB::table('hr_staff_targets')->insert(['company_id' => $companyId, 'user_id' => $userId, 'month' => $month, 'monthly_target' => $amount, 'created_at' => now(), 'updated_at' => now()]); }
        if ($month === null) { HrStaffProfile::forUser($companyId, $userId)->update(['monthly_target' => $amount]); }   // the profile keeps the common one for older readers
    }

    public function targets(int $companyId, int $userId): array
    {
        return DB::table('hr_staff_targets')->where('company_id', $companyId)->where('user_id', $userId)->orderByRaw('month is not null')->orderByDesc('month')->get(['id', 'month', 'monthly_target'])->all();
    }

    // ── category targets + incentive % ─────────────────────────────────────────

    /**
     * For one month: every category row that applies (that month's own row beats the every-month row of the same category) with what the
     * person achieved — the sale value of their leads enrolled that month in that category — and the incentive that earns.
     */
    public function categoryProgress(int $companyId, int $userId, ?string $month): array
    {
        $month = $this->ym($month);
        $from = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $to = $from->copy()->endOfMonth();
        $rows = DB::table('hr_category_targets')->where('company_id', $companyId)->where('user_id', $userId)
            ->where(fn ($q) => $q->where('month', $month)->orWhereNull('month'))->get()
            ->groupBy('category')->map(fn ($g) => $g->firstWhere('month', $month) ?? $g->first());

        return $rows->map(function ($r) use ($companyId, $userId, $from, $to) {
            $leads = DB::table('leads')->where('company_id', $companyId)->where('assigned_to', $userId)->whereNull('deleted_at')
                ->whereBetween('enrolled_at', [$from, $to])
                ->where(fn ($q) => $q->where('category', $r->category)
                    ->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from('lead_category_links')->whereColumn('lead_category_links.lead_id', 'leads.id')->where('lead_category_links.name', $r->category)));
            $achieved = round($this->categoryAchieved($companyId, $userId, $from, $to, $r->category, clone $leads), 2);
            $target = (float) $r->target_amount;

            return [
                'id' => $r->id, 'category' => $r->category, 'month' => $r->month, 'scope' => $r->month ? 'month' : 'every month',
                'target_amount' => $target, 'incentive_percent' => (float) $r->incentive_percent,
                'enrolled' => (clone $leads)->count(), 'achieved' => $achieved,
                'progress' => $target > 0 ? round(min($achieved / $target * 100, 999), 1) : null,
                'incentive' => round($achieved * (float) $r->incentive_percent / 100, 2),
            ];
        })->sortBy('category')->values()->all();
    }

    /**
     * What counts toward a category target. Normally the full sale value of the leads enrolled in the month. For leads on an instalment plan,
     * and when HR counts collected cash for category targets too, it is the money credited in that month (the sales the payments created).
     */
    private function categoryAchieved(int $companyId, int $userId, Carbon $from, Carbon $to, string $category, $enrolledInMonth): float
    {
        $set = \Illuminate\Support\Facades\Schema::hasTable('installment_settings') ? app(\App\Modules\Lead\Services\InstallmentService::class)->settings($companyId) : null;
        if (!$set || $set['target_basis'] === 'full' || !$set['category_follow_basis']) { return (float) $enrolledInMonth->sum('sale_value'); }

        $onPlan = DB::table('lead_installment_plans')->where('company_id', $companyId)->whereIn('status', ['active', 'completed'])->pluck('lead_id')->all();
        $plain = (float) (clone $enrolledInMonth)->when($onPlan, fn ($q) => $q->whereNotIn('id', $onPlan))->sum('sale_value');

        $ids = DB::table('leads')->where('company_id', $companyId)->where('assigned_to', $userId)->whereNull('deleted_at')->whereIn('id', $onPlan)
            ->where(fn ($q) => $q->where('category', $category)
                ->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from('lead_category_links')->whereColumn('lead_category_links.lead_id', 'leads.id')->where('lead_category_links.name', $category)))
            ->pluck('id')->all();
        $credited = $ids ? (float) DB::table('listing_sales')->where('company_id', $companyId)->where('staff_id', $userId)->whereIn('lead_id', $ids)->whereNull('deleted_at')
            ->whereBetween('sold_at', [$from->toDateString(), $to->toDateString()])->sum('amount') : 0.0;

        return $plain + $credited;
    }

    public function categoryRows(int $companyId, int $userId): array
    {
        return DB::table('hr_category_targets')->where('company_id', $companyId)->where('user_id', $userId)->orderBy('category')->orderByRaw('month is not null')->orderByDesc('month')
            ->get(['id', 'category', 'month', 'target_amount', 'incentive_percent'])->all();
    }
}
