<?php
namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyPlan;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Razorpay\Api\Api;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use App\Support\CouponService;

class PaymentController extends Controller
{
    // ── Create Razorpay order ─────────────────────────────────────────────
    public function createOrder(Request $request)
    {
        $d = $request->validate([
            'plan_id'       => ['required', 'exists:plans,id'],
            'duration_type' => ['required', 'in:monthly,3month,6month,yearly'],
            'company_name'  => ['required', 'string', 'max:100'],
            'name'          => ['required', 'string', 'max:100'],
            'email'         => ['required', 'email'],
            'phone'         => ['required', 'string', 'max:20'],
            'password'      => ['required', 'string', 'min:8'],
            'coupon_code'   => ['nullable', 'string', 'max:40'],
        ]);

        $svc   = app(PlanPurchaseService::class);
        $quote = $svc->quotePlan(null, (int) $d['plan_id'], $d['duration_type'], $d['coupon_code'] ?? null, $d['email']);
        $plan  = $quote['plan'];
        $price = $quote['final'];

        // A coupon that covers everything: no gateway, the account is created right away.
        if ($price <= 0) {
            $pending = $this->pending($d, $plan, $quote, 'FREE_' . uniqid());
            $this->completeRegistration($pending, ['gateway' => 'free']);
            session()->flash('paid', true); session()->flash('email', $pending['email']); session()->flash('company', $pending['company_name']);
            return response()->json(['free' => true, 'redirect' => route('payment.success')]);
        }

        $api   = new Api(config('services.razorpay.key'), config('services.razorpay.secret'));
        $order = $api->order->create([
            'amount'   => (int)($price * 100),
            'currency' => 'INR',
            'receipt'  => 'LANDING_' . uniqid(),
            'notes'    => [
                'coupon'        => $quote['coupon']?->code,
                'plan_id'       => $plan->id,
                'plan_name'     => $plan->name,
                'duration_type' => $d['duration_type'],
                'company_name'  => $d['company_name'],
                'email'         => $d['email'],
            ],
        ]);

        // Store pending data in session
        session(['pending_registration' => $this->pending($d, $plan, $quote, $order->id)]);

        return response()->json([
            'order_id'     => $order->id,
            'amount'       => (int)($price * 100),
            'currency'     => 'INR',
            'razorpay_key' => config('services.razorpay.key'),
            'plan_name'    => $plan->name,
            'duration'     => $d['duration_type'],
        ]);
    }

    // ── Verify payment + activate account ────────────────────────────────
    public function verifyPayment(Request $request)
    {
        $request->validate([
            'razorpay_order_id'   => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature'  => ['required', 'string'],
        ]);

        // Verify signature
        $expected = hash_hmac(
            'sha256',
            $request->razorpay_order_id . '|' . $request->razorpay_payment_id,
            config('services.razorpay.secret')
        );

        if (!hash_equals($expected, $request->razorpay_signature)) {
            return redirect()->route('payment.failed')->with('error', 'Payment verification failed. Please contact support.');
        }

        $pending = session('pending_registration');
        if (!$pending) {
            return redirect()->route('payment.failed')->with('error', 'Session expired. Please try again.');
        }

        try {
            $this->completeRegistration($pending, [
                'gateway' => 'razorpay', 'payment_id' => $request->razorpay_payment_id, 'signature' => $request->razorpay_signature,
            ]);

            session()->forget('pending_registration');

            return redirect()->route('payment.success')
                ->with('paid', true)
                ->with('email', $pending['email'])
                ->with('company', $pending['company_name']);

        } catch (\Exception $e) {
            return redirect()->route('payment.failed')->with('error', $e->getMessage());
        }
    }

    public function success()
    {
        return view('landing.payment-success');
    }

    public function failed()
    {
        return view('landing.payment-failed');
    }

    /** What we remember between creating the order and the payment coming back. */
    private function pending(array $d, Plan $plan, array $quote, string $orderId): array
    {
        return [
            'plan_id' => $plan->id, 'duration_type' => $d['duration_type'], 'company_name' => $d['company_name'], 'name' => $d['name'],
            'email' => $d['email'], 'phone' => $d['phone'], 'password' => $d['password'],
            'amount' => $quote['final'], 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'],
            'coupon_id' => $quote['coupon']?->id, 'coupon_code' => $quote['coupon']?->code, 'order_id' => $orderId,
        ];
    }

    /** Create the company, owner, wallet, payment record, plan record and (if used) the coupon redemption — all or nothing. */
    private function completeRegistration(array $pending, array $payment): void
    {
        DB::transaction(function () use ($pending, $payment) {
            $plan = Plan::findOrFail($pending['plan_id']);

            $company = Company::create([
                'name'         => $pending['company_name'],
                'slug'         => Str::slug($pending['company_name']) . '-' . Str::random(4),
                'email'        => $pending['email'],
                'phone'        => $pending['phone'],
                'app_id'       => 'WA_APP_' . strtoupper(Str::random(12)),
                'plan_id'      => $plan->id,
                'status'       => 'active',
                'plan_expires_at' => $this->calcExpiry(now(), $pending['duration_type']),
                'webhook_verify_token' => Str::random(32),
            ]);

            Wallet::create(['company_id' => $company->id, 'balance' => $plan->messages_limit, 'total_purchased' => $plan->messages_limit, 'total_used' => 0]);

            $ownerRole = Role::where('name', 'owner')->first();
            $owner = User::create([
                'company_id' => $company->id, 'role_id' => $ownerRole?->id, 'name' => $pending['name'], 'email' => $pending['email'],
                'phone' => $pending['phone'], 'password' => Hash::make($pending['password']), 'is_active' => true,
            ]);

            $order = PaymentOrder::create([
                'company_id' => $company->id, 'user_id' => $owner->id, 'razorpay_order_id' => $pending['order_id'],
                'razorpay_payment_id' => $payment['payment_id'] ?? null, 'razorpay_signature' => $payment['signature'] ?? null,
                'amount' => $pending['amount'], 'subtotal' => $pending['subtotal'] ?? $pending['amount'], 'discount' => $pending['discount'] ?? 0,
                'coupon_id' => $pending['coupon_id'] ?? null, 'coupon_code' => $pending['coupon_code'] ?? null,
                'messages_credit' => 0, 'status' => 'paid', 'purpose' => 'plan', 'gateway' => $payment['gateway'] ?? 'razorpay', 'paid_at' => now(),
                'meta' => ['plan_id' => $plan->id, 'duration_type' => $pending['duration_type'], 'signup' => true],
            ]);

            CompanyPlan::create([
                'company_id' => $company->id, 'plan_id' => $plan->id, 'payment_order_id' => $order->id, 'duration_type' => $pending['duration_type'],
                'amount_paid' => $pending['amount'], 'status' => 'active', 'starts_at' => now(), 'expires_at' => $this->calcExpiry(now(), $pending['duration_type']),
            ]);

            app(CouponService::class)->redeem($order, $pending['email']);
        });
    }

    private function calcPrice(float $base, string $dur): float
    {
        return match ($dur) {
            'monthly' => $base,
            '3month'  => $base * 3 * 0.95,
            '6month'  => $base * 6 * 0.90,
            'yearly'  => $base * 12 * 0.80,
            default   => $base,
        };
    }

    private function calcExpiry(\Carbon\Carbon $start, string $dur): \Carbon\Carbon
    {
        return match ($dur) {
            'monthly' => $start->copy()->addMonth(),
            '3month'  => $start->copy()->addMonths(3),
            '6month'  => $start->copy()->addMonths(6),
            'yearly', '12month' => $start->copy()->addYear(),
            default   => $start->copy()->addMonth(),
        };
    }
}
