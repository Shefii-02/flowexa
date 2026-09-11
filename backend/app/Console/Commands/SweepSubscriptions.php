<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SweepSubscriptions
 *
 * Runs daily. Enforces the lifecycle that the request-time middleware only
 * approximates:
 *
 *  1. Trial companies whose trial_ends_at + grace has passed  → status "expired"
 *  2. Paid companies whose plan_expires_at + grace has passed → status "expired"
 *  3. Any CompanyPlan row whose expires_at has passed          → status "expired"
 *
 * The grace window (config billing.grace_days) keeps an account usable for a
 * few days after the paid period ends so a late renewal doesn't lock people
 * out. Once a company is marked "expired", EnsureCompanyActiveV2 blocks all
 * data routes with a 402 until they renew.
 */
class SweepSubscriptions extends Command
{
    protected $signature   = 'subscriptions:sweep {--dry-run : Report what would change without writing}';
    protected $description  = 'Expire trials and lapsed paid plans past their grace window';

    public function handle(): int
    {
        $grace  = (int) config('billing.grace_days', 3);
        $cutoff = now()->subDays($grace);
        $dry    = (bool) $this->option('dry-run');

        // ── 1 + 2. Companies whose trial / paid period + grace has lapsed ──────
        $lapsed = Company::query()
            ->whereIn('status', ['trial', 'active'])
            ->where(function ($q) use ($cutoff) {
                $q->where(fn ($q) => $q->where('status', 'trial')
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '<', $cutoff))
                  ->orWhere(fn ($q) => $q->where('status', 'active')
                        ->whereNotNull('plan_expires_at')
                        ->where('plan_expires_at', '<', $cutoff));
            })
            ->get();

        foreach ($lapsed as $company) {
            $reason = $company->status === 'trial' ? 'trial ended' : 'plan expired';
            $this->line("  · #{$company->id} {$company->name} → expired ({$reason})");
            if (! $dry) {
                $company->update(['status' => 'expired']);
            }
        }

        // ── 3. CompanyPlan history rows past their own expiry ─────────────────
        $planQuery = CompanyPlan::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        $stalePlans = (clone $planQuery)->count();
        if (! $dry && $stalePlans) {
            $planQuery->update(['status' => 'expired']);
        }

        $summary = sprintf(
            '%s%d company(ies) expired, %d plan row(s) closed (grace %dd)',
            $dry ? '[dry-run] ' : '',
            $lapsed->count(),
            $stalePlans,
            $grace,
        );

        $this->info($summary);
        if (! $dry && ($lapsed->count() || $stalePlans)) {
            Log::info("[subscriptions:sweep] {$summary}");
        }

        return self::SUCCESS;
    }
}
