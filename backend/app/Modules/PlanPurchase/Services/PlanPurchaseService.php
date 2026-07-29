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

    // ── Create Razorpay order for plan purchase ───────────────────────────────
    public function createPlanOrder(int $companyId, int $userId, int $planId, string $durationType): array
    {
        $plan = Plan::findOrFail($planId);
         $user = auth()->user();
        // Calculate price based on duration
        $price = $this->calculatePrice($plan->price, $durationType);

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

    // ── Create addon order ────────────────────────────────────────────────────
    public function createAddonOrder(int $companyId, int $userId, int $addonId): array
    {
        $addon = Addon::findOrFail($addonId);
        $api   = new \Razorpay\Api\Api(config('services.razorpay.key'), config('services.razorpay.secret'));
        $order = $api->order->create([
            'amount'   => (int)($addon->price * 100),
            'currency' => 'INR',
            'receipt'  => 'ADDON_' . uniqid(),
        ]);

        PaymentOrder::create([
            'company_id'        => $companyId,
            'user_id'           => $userId,
            'razorpay_order_id' => $order->id,
            'amount'            => $addon->price,
            'messages_credit'   => 0,
            'status'            => 'pending',
        ]);

        return ['order_id' => $order->id, 'amount' => (int)($addon->price * 100), 'razorpay_key' => config('services.razorpay.key'), 'addon' => $addon];
    }

    // ── Verify addon payment ──────────────────────────────────────────────────
    public function verifyAddonPayment(int $companyId, array $data): CompanyAddon
    {
        $order = PaymentOrder::where('razorpay_order_id', $data['razorpay_order_id'])->where('company_id', $companyId)->where('status', 'pending')->firstOrFail();
        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], config('services.razorpay.secret'));
        if (!hash_equals($expected, $data['razorpay_signature'])) { $order->update(['status' => 'failed']); throw new \Exception('Signature invalid.'); }
        $order->update(['razorpay_payment_id' => $data['razorpay_payment_id'], 'status' => 'paid']);
        $addon   = Addon::findOrFail($data['addon_id']);
        $startsAt= now();
        $expires = $addon->billing_cycle === 'one_time' ? null : $startsAt->copy()->addMonth();
        return CompanyAddon::create(['company_id' => $companyId,'addon_id' => $addon->id,'payment_order_id' => $order->id,'amount_paid' => $order->amount,'status' => 'active','starts_at' => $startsAt,'expires_at' => $expires]);
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
    private function calculatePrice(float $base, string $durationType): float
    {
        return match ($durationType) {
            'monthly'  => $base,
            '3month'   => $base * 3 * 0.95,    // 5% off
            '6month'   => $base * 6 * 0.90,    // 10% off
            'yearly'   => $base * 12 * 0.80,   // 20% off
            '12month'  => $base * 12 * 0.80,
            default    => $base,
        };
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
