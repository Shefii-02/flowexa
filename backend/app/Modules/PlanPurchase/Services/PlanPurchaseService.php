<?php
namespace App\Modules\PlanPurchase\Services;

use App\Models\Addon;
use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\CompanyPlan;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\TopupPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanPurchaseService
{
    // ── Public plan list ──────────────────────────────────────────────────────
    public function publicPlans(): \Illuminate\Database\Eloquent\Collection
    {
        return Plan::where('is_active', true)->where('is_custom', false)->orderBy('price')->get();
    }

    // ── Active plans for company ──────────────────────────────────────────────
    public function activePlans(): \Illuminate\Database\Eloquent\Collection
    {
        return Plan::where('is_active', true)->where('is_custom', false)->orderBy('price')->get();
    }

    // ── Current plan for company ──────────────────────────────────────────────
    public function currentPlan(int $companyId): ?CompanyPlan
    {
        return CompanyPlan::where('company_id', $companyId)
            ->where('status', 'active')
            ->with('plan')
            ->latest()
            ->first();
    }

    // ── Preview an upgrade / downgrade / renewal ──────────────────────────────
    // Returns the change type, the price for the chosen duration, and — for a
    // downgrade — any current usage that exceeds the target plan's limits and
    // would have to be trimmed first.
    public function changePreview(int $companyId, int $planId, string $durationType): array
    {
        $company = Company::findOrFail($companyId);
        $target  = Plan::findOrFail($planId);
        $current = $company->plan;

        $type = 'new';
        if ($current) {
            $type = match (true) {
                $current->id === $target->id       => 'renew',
                (float) $target->price > (float) $current->price => 'upgrade',
                (float) $target->price < (float) $current->price => 'downgrade',
                default                            => 'change',
            };
        }

        $blockers = [];
        foreach (\App\Support\PlanLimits::REGISTRY as $resource => [$label, , , $kind]) {
            if ($kind !== 'total') continue;                       // monthly caps reset on their own — they never block a switch
            $max = \App\Support\PlanLimits::limit($target, $resource, $companyId);
            if ($max === null) continue;                           // unlimited on the target plan

            $used = \App\Support\PlanLimits::used($companyId, $resource);   // soft-deleted rows do not count
            if ($used > $max) {
                $blockers[] = "You have {$used} " . strtolower($label) . "; {$target->name} allows {$max}. Remove " . ($used - $max) . " before switching.";
            }
        }

        return [
            'type'         => $type,
            'current_plan' => $current ? ['id' => $current->id, 'name' => $current->name, 'price' => (float) $current->price] : null,
            'target_plan'  => ['id' => $target->id, 'name' => $target->name, 'price' => (float) $target->price],
            'duration_type' => $durationType,
            'amount'       => round($this->calculatePrice($target, $durationType), 2),
            'blockers'     => $blockers,
            'can_proceed'  => count($blockers) === 0,
        ];
    }

    // ── Create Razorpay order for plan purchase ───────────────────────────────
    public function createPlanOrder(int $companyId, int $userId, int $planId, string $durationType): array
    {
        $plan = Plan::findOrFail($planId);
         $user = auth()->user();

        // Guard: block a downgrade while current usage still exceeds the
        // target plan's limits (mirrors PlanLimitMiddleware at create time).
        $preview = $this->changePreview($companyId, $planId, $durationType);
        if (! $preview['can_proceed']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => $preview['blockers'],
            ]);
        }

        // Calculate price based on duration
        $price = $this->calculatePrice($plan, $durationType);

        $api   = new \Razorpay\Api\Api(config('services.razorpay.key'), config('services.razorpay.secret'));
        $order = $api->order->create([
            'amount'   => (int) ($price * 100),
            'currency' => 'INR',
            'receipt'  => 'PLAN_' . uniqid(),
            'notes'    => ['company_id' => $companyId, 'plan_id' => $planId, 'duration' => $durationType],
        ]);

        PaymentOrder::create([
            'company_id'        => $companyId,
            'user_id'           => $userId,
            'razorpay_order_id' => $order->id,
            'amount'            => $price,
            'messages_credit'   => 0,  // plan purchase, not wallet topup
            'status'            => 'pending',
        ]);

        return [
            'order_id'      => $order->id,
            'amount'        => (int)($price * 100),
            'currency'      => 'INR',
            'razorpay_key'  => config('services.razorpay.key'),
            'plan'          => ['id' => $plan->id, 'name' => $plan->name, 'price' => $price],
            'duration_type' => $durationType,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]
        ];
    }

    // ── Verify plan payment + activate ────────────────────────────────────────
    public function verifyPlanPayment(int $companyId, array $data): CompanyPlan
    {
        $order = PaymentOrder::where('razorpay_order_id', $data['razorpay_order_id'])
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->firstOrFail();

        // Verify signature
        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], config('services.razorpay.secret'));
        if (!hash_equals($expected, $data['razorpay_signature'])) {
            $order->update(['status' => 'failed']);
            throw new \Exception('Payment signature verification failed.');
        }

        $order->update([
            'razorpay_payment_id' => $data['razorpay_payment_id'],
            'razorpay_signature'  => $data['razorpay_signature'],
            'status'              => 'paid',
        ]);

        return DB::transaction(function () use ($companyId, $data, $order) {
            $planId       = $data['plan_id'];
            $durationType = $data['duration_type'];
            $plan         = Plan::findOrFail($planId);

            // Expire old active plan
            CompanyPlan::where('company_id', $companyId)->where('status', 'active')
                ->update(['status' => 'upgraded']);

            $startsAt  = now();
            $expiresAt = $this->calculateExpiry($startsAt, $durationType, $plan->duration_months);

            $companyPlan = CompanyPlan::create([
                'company_id'       => $companyId,
                'plan_id'          => $planId,
                'payment_order_id' => $order->id,
                'duration_type'    => $durationType,
                'duration_months'  => $plan->duration_months,
                'amount_paid'      => $order->amount,
                'status'           => 'active',
                'starts_at'        => $startsAt,
                'expires_at'       => $expiresAt,
            ]);

            // Update company's plan + expiry
            Company::where('id', $companyId)->update([
                'plan_id'         => $planId,
                'status'          => 'active',
                'plan_expires_at' => $expiresAt,
            ]);

            return $companyPlan->load('plan');
        });
    }

    // ── Purchase history ──────────────────────────────────────────────────────
    public function history(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyPlan::where('company_id', $companyId)->with('plan')->latest()->get();
    }

    // ── Addons ────────────────────────────────────────────────────────────────
    public function activeAddons(): \Illuminate\Database\Eloquent\Collection
    {
        return Addon::active()->get();
    }

    /** The company's own add-on purchases (newest first). */
    public function companyAddons(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyAddon::where('company_id', $companyId)->with('addon')->latest()->get();
    }

    /**
     * Every payment this company completed — plan purchases, add-ons and message top-ups — each described by what it bought.
     * (These are payment receipts; they carry no tax breakdown.)
     */
    public function invoices(int $companyId): array
    {
        $orders = PaymentOrder::where('company_id', $companyId)->where('status', 'paid')->latest()->get();
        $ids    = $orders->pluck('id');
        $plans  = CompanyPlan::with('plan')->whereIn('payment_order_id', $ids)->get()->keyBy('payment_order_id');
        $addons = CompanyAddon::with('addon')->whereIn('payment_order_id', $ids)->get()->keyBy('payment_order_id');
        $names  = ['monthly' => '1 month', '3month' => '3 months', '6month' => '6 months', 'yearly' => '12 months', '12month' => '12 months'];

        return $orders->map(function (PaymentOrder $o) use ($plans, $addons, $names) {
            $cp = $plans->get($o->id); $ca = $addons->get($o->id);
            [$kind, $what] = match (true) {
                (bool) $cp => ['plan', ($cp->plan?->name ?? 'Plan') . ' plan — ' . ($names[$cp->duration_type] ?? $cp->duration_type)],
                (bool) $ca => ['addon', 'Add-on: ' . ($ca->addon?->name ?? 'Add-on')],
                default    => ['topup', 'Message top-up' . ($o->messages_credit ? ' (' . number_format((int) $o->messages_credit) . ' messages)' : '')],
            };
            return [
                'id' => $o->id, 'date' => $o->created_at, 'kind' => $kind, 'description' => $what, 'amount' => (float) $o->amount,
                'razorpay_order_id' => $o->razorpay_order_id, 'razorpay_payment_id' => $o->razorpay_payment_id,
                'period_start' => ($cp ?? $ca)?->starts_at, 'period_end' => ($cp ?? $ca)?->expires_at,
            ];
        })->all();
    }

    // ── Create addon order ────────────────────────────────────────────────────
    public function createAddonOrder(int $companyId, int $userId, int $addonId, ?string $cycle = null): array
    {
        $addon  = Addon::findOrFail($addonId);
        abort_unless($addon->is_active, 422, 'This add-on is not available.');
        $cycle  = $this->addonCycle($addon, $cycle);
        $amount = $this->addonPrice($addon, $cycle);
        $api    = new \Razorpay\Api\Api(config('services.razorpay.key'), config('services.razorpay.secret'));
        $order  = $api->order->create([
            'amount'   => (int) round($amount * 100),
            'currency' => 'INR',
            'receipt'  => 'ADDON_' . uniqid(),
            'notes'    => ['company_id' => $companyId, 'addon_id' => $addonId, 'cycle' => $cycle],
        ]);

        PaymentOrder::create([
            'company_id'        => $companyId,
            'user_id'           => $userId,
            'razorpay_order_id' => $order->id,
            'amount'            => $amount,
            'messages_credit'   => 0,
            'status'            => 'pending',
        ]);

        return ['order_id' => $order->id, 'amount' => (int) round($amount * 100), 'billing_cycle' => $cycle, 'razorpay_key' => config('services.razorpay.key'), 'addon' => $addon];
    }

    // ── Verify addon payment ──────────────────────────────────────────────────
    public function verifyAddonPayment(int $companyId, array $data): CompanyAddon
    {
        $order = PaymentOrder::where('razorpay_order_id', $data['razorpay_order_id'])->where('company_id', $companyId)->where('status', 'pending')->firstOrFail();
        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], config('services.razorpay.secret'));
        if (!hash_equals($expected, $data['razorpay_signature'])) { $order->update(['status' => 'failed']); throw new \Exception('Signature invalid.'); }
        $addon = Addon::findOrFail($data['addon_id']);
        $cycle = $this->addonCycle($addon, $data['billing_cycle'] ?? null);
        // The amount actually paid must be this add-on's price for this cycle — otherwise a cheap order could be claimed as a
        // dearer add-on, or a monthly payment as a yearly one.
        if (abs((float) $order->amount - $this->addonPrice($addon, $cycle)) > 0.01) {
            $order->update(['status' => 'failed']);
            \Log::warning('Add-on payment amount mismatch', ['company_id' => $companyId, 'addon_id' => $addon->id, 'cycle' => $cycle, 'paid' => (float) $order->amount, 'order' => $order->razorpay_order_id]);
            throw new \Exception('The amount paid does not match this add-on. Contact support with your payment reference.');
        }
        $order->update(['razorpay_payment_id' => $data['razorpay_payment_id'], 'status' => 'paid']);
        $startsAt = now();
        $expires  = match ($cycle) { 'one_time' => null, 'yearly' => $startsAt->copy()->addYear(), default => $startsAt->copy()->addMonth() };
        return CompanyAddon::create(['company_id' => $companyId,'addon_id' => $addon->id,'payment_order_id' => $order->id,'amount_paid' => $order->amount,'status' => 'active','billing_cycle' => $cycle,'starts_at' => $startsAt,'expires_at' => $expires]);
    }

    // ── Superadmin: assign custom plan ────────────────────────────────────────
    public function assignCustomPlan(int $companyId, int $planId, ?string $expiresAt, ?string $notes): CompanyPlan
    {
        CompanyPlan::where('company_id', $companyId)->where('status', 'active')->update(['status' => 'upgraded']);
        $plan = Plan::findOrFail($planId);
        $cp   = CompanyPlan::create([
            'company_id'      => $companyId,
            'plan_id'         => $planId,
            'duration_type'   => 'custom',
            'amount_paid'     => 0,
            'status'          => 'active',
            'starts_at'       => now(),
            'expires_at'      => $expiresAt,
            'notes'           => $notes,
        ]);
        Company::where('id', $companyId)->update(['plan_id' => $planId,'plan_expires_at' => $expiresAt,'status' => 'active']);
        return $cp->load('plan');
    }

    // ── Superadmin: topup packages CRUD ──────────────────────────────────────
    public function topupPackages(): \Illuminate\Database\Eloquent\Collection { return TopupPackage::orderBy('sort_order')->get(); }
    public function createTopupPackage(array $d): TopupPackage   { return TopupPackage::create($d); }
    public function updateTopupPackage(int $id, array $d): TopupPackage { $p = TopupPackage::findOrFail($id); $p->update($d); return $p->fresh(); }
    public function deleteTopupPackage(int $id): void            { TopupPackage::findOrFail($id)->delete(); }
    public function createAddon(array $d): Addon                 { return Addon::create($d); }
    public function updateAddon(int $id, array $d): Addon        { $a = Addon::findOrFail($id); $a->update($d); return $a->fresh(); }
    public function superAdminPlans(): \Illuminate\Database\Eloquent\Collection { return Plan::withCount('companies')->get(); }
    public function superAdminAddons(): \Illuminate\Database\Eloquent\Collection { return Addon::get(); }

    // ── Helpers ───────────────────────────────────────────────────────────────
    /** Plan price for a duration. A yearly price set by the superadmin wins; otherwise the old 20%-off rule applies. */
    public function calculatePrice(Plan $plan, string $durationType): float
    {
        $base = (float) $plan->price;
        $yearly = $plan->price_yearly !== null ? (float) $plan->price_yearly : $base * 12 * 0.80;
        return match ($durationType) {
            'monthly'  => $base,
            '3month'   => $base * 3 * 0.95,    // 5% off
            '6month'   => $base * 6 * 0.90,    // 10% off
            'yearly', '12month' => $yearly,
            default    => $base,
        };
    }

    /** What an add-on costs for a cycle. One-time add-ons have a single price; yearly falls back to 12 × monthly. */
    public function addonPrice(Addon $addon, string $cycle): float
    {
        if ($addon->billing_cycle === 'one_time') { return (float) $addon->price; }
        return $cycle === 'yearly'
            ? (float) ($addon->price_yearly ?? $addon->price * 12)
            : (float) $addon->price;
    }

    /** The cycle a purchase actually uses: one-time add-ons ignore the request; everything else is monthly or yearly. */
    public function addonCycle(Addon $addon, ?string $requested): string
    {
        if ($addon->billing_cycle === 'one_time') { return 'one_time'; }
        return $requested === 'yearly' ? 'yearly' : 'monthly';
    }

    private function calculateExpiry(\Carbon\Carbon $start, string $durationType, ?int $months): ?\Carbon\Carbon
    {
        if ($durationType === 'unlimited') return null;
        $m = match ($durationType) {
            'monthly'  => 1,
            '3month'   => 3,
            '6month'   => 6,
            'yearly'   => 12,
            '12month'  => 12,
            'custom'   => $months ?? 1,
            default    => 1,
        };
        return $start->copy()->addMonths($m);
    }
}
