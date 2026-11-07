<?php

namespace App\Modules\PlanPurchase\Services;

use App\Models\Company;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\DB;

/**
 * What a company should be told about its subscription right now: a plan or trial that is ending/ended, add-ons that are
 * about to lapse (or just did), and limits that are exceeded because capacity went away. Computed on request from the
 * database, so a renewal clears the notice immediately and nothing needs a scheduler.
 *
 * Policy when an add-on lapses: everything the company created stays exactly as it is — nothing is deleted or hidden —
 * but no NEW items of that kind can be added until capacity returns (renew, upgrade, or remove some).
 */
class PlanNoticeService
{
    /** @return list<array{id:string,type:string,severity:string,title:string,message:string,days_left:?int,cta:array{label:string,url:string}}> */
    public function forCompany(Company $company): array
    {
        $notices = [];
        $plan = $company->plan;

        // ── plan / trial ──
        $onTrial = $company->status === 'trial' && $company->trial_ends_at;
        $endsAt  = $onTrial ? $company->trial_ends_at : $company->plan_expires_at;
        if ($endsAt) {
            $days  = (int) ceil(now()->floatDiffInDays($endsAt, false));
            $alert = (int) ($plan?->alert_before_days ?? 7);
            $what  = $onTrial ? 'free trial' : ($plan?->name ? "{$plan->name} plan" : 'plan');
            if ($days < 0) {
                $notices[] = $this->n('plan-ended', 'plan_expired', 'critical', "Your {$what} has ended",
                    'Renew now to keep using Teamzo. Your data is safe.', $days, 'Choose a plan', '/plan-purchase?section=plans');
            } elseif ($days <= $alert) {
                $notices[] = $this->n('plan-ending', 'plan_expiring', 'warning', "Your {$what} ends " . $this->when($days),
                    'Renew before it ends so nothing is interrupted.', $days, $onTrial ? 'Choose a plan' : 'Renew plan', '/plan-purchase?section=plans');
            }
        }

        // ── add-ons ──
        $addonAlert = (int) config('billing.addon_alert_days', 7);
        $rows = DB::table('company_addons as ca')->join('addons as a', 'a.id', '=', 'ca.addon_id')
            ->where('ca.company_id', $company->id)->where('ca.status', 'active')->whereNotNull('ca.expires_at')
            ->where('ca.expires_at', '>', now()->subDays(30))->where('ca.expires_at', '<=', now()->addDays($addonAlert))
            ->orderBy('ca.expires_at')->get(['ca.id', 'ca.expires_at', 'a.name', 'a.type']);
        foreach ($rows as $r) {
            $days = (int) ceil(now()->floatDiffInDays($r->expires_at, false));
            if ($days < 0) {
                $notices[] = $this->n("addon-ended-{$r->id}", 'addon_expired', 'warning', "Add-on “{$r->name}” has expired",
                    'Your own data is kept, but you can’t add more beyond your plan’s limit until you renew it.', $days, 'Renew add-on', '/plan-purchase?section=addons');
            } else {
                $notices[] = $this->n("addon-ending-{$r->id}", 'addon_expiring', 'warning', "Add-on “{$r->name}” ends " . $this->when($days),
                    'Renew it to keep the extra capacity. If it lapses, what you added stays, but you can’t add more.', $days, 'Renew add-on', '/plan-purchase?section=addons');
            }
        }

        // ── limits that are exceeded (capacity went away after items were created) ──
        foreach (PlanLimits::usage((int) $company->id, $plan) as $key => $u) {
            if (($u['over_by'] ?? 0) > 0) {
                $notices[] = $this->n("over-{$key}", 'over_limit', 'critical', "{$u['label']}: {$u['used']} used, {$u['limit']} allowed",
                    "You have {$u['over_by']} more than your plan allows. Everything is kept, but you can’t add more until you renew an add-on, upgrade your plan, or remove some.",
                    null, PlanLimits::addonOffered($key) ? 'Add more' : 'Upgrade plan',
                    PlanLimits::addonOffered($key) ? "/plan-purchase?section=addons&resource={$key}" : '/plan-purchase?section=plans');
            }
        }

        return $notices;
    }

    private function when(int $days): string
    {
        return $days <= 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");
    }

    private function n(string $id, string $type, string $severity, string $title, string $message, ?int $days, string $cta, string $url): array
    {
        return ['id' => $id, 'type' => $type, 'severity' => $severity, 'title' => $title, 'message' => $message,
                'days_left' => $days, 'cta' => ['label' => $cta, 'url' => $url]];
    }
}
