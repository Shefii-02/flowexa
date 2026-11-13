<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\PaymentOrder;
use Illuminate\Validation\ValidationException;

/**
 * Coupon rules in one place: is this code usable for this purchase, and what does it take off?
 * A redemption is only recorded when the payment actually succeeds (see redeem()), so an abandoned checkout never burns a use.
 */
class CouponService
{
    /**
     * @param string      $purpose  plan | addon | topup
     * @param int|null    $itemId   plan id / (for a cart) null
     * @param int[]       $addonIds add-on ids in the cart
     * @return array{coupon:Coupon, subtotal:float, discount:float, final:float}
     * @throws ValidationException with a user-facing message under `coupon_code`
     */
    public function evaluate(string $code, float $subtotal, string $purpose, ?int $companyId = null, ?int $planId = null, array $addonIds = [], ?string $duration = null, ?string $email = null): array
    {
        $coupon = Coupon::where('code', Coupon::normalize($code))->first();
        $fail = fn (string $m) => throw ValidationException::withMessages(['coupon_code' => $m]);

        if (!$coupon || !$coupon->is_active)                                  { $fail('This coupon code is not valid.'); }
        if ($coupon->starts_at && $coupon->starts_at->isFuture())             { $fail('This coupon is not active yet.'); }
        if ($coupon->expires_at && $coupon->expires_at->isPast())             { $fail('This coupon has expired.'); }
        if ($coupon->company_id && $coupon->company_id !== $companyId)        { $fail('This coupon is not valid for your account.'); }
        if ($coupon->applies_to && !in_array($purpose, $coupon->applies_to, true)) { $fail('This coupon does not apply to this kind of purchase.'); }
        if ($coupon->plan_ids && $purpose === 'plan' && !in_array($planId, $coupon->plan_ids, true)) { $fail('This coupon is not valid for the selected plan.'); }
        if ($coupon->addon_ids && $purpose === 'addon' && array_diff($addonIds, $coupon->addon_ids) === $addonIds) { $fail('This coupon is not valid for the selected add-ons.'); }
        if ($coupon->durations && $duration && !in_array($duration, $coupon->durations, true)) { $fail('This coupon is not valid for the selected billing period.'); }
        if ($coupon->min_amount && $subtotal < (float) $coupon->min_amount)   { $fail('Spend at least ₹' . number_format((float) $coupon->min_amount) . ' to use this coupon.'); }

        if ($coupon->max_redemptions && $coupon->redemptions()->count() >= $coupon->max_redemptions) { $fail('This coupon has been fully redeemed.'); }
        if ($companyId) {
            if ($coupon->redemptions()->where('company_id', $companyId)->count() >= max(1, (int) $coupon->per_company_limit)) { $fail('You have already used this coupon.'); }
            if ($coupon->first_purchase_only && PaymentOrder::where('company_id', $companyId)->where('status', 'paid')->where('purpose', '!=', 'topup')->exists()) {
                $fail('This coupon is only for a first purchase.');
            }
        } elseif ($email && $coupon->redemptions()->where('email', $email)->count() >= max(1, (int) $coupon->per_company_limit)) {
            $fail('You have already used this coupon.');
        }

        $discount = $coupon->discount_type === 'flat'
            ? (float) $coupon->discount_value
            : $subtotal * (float) $coupon->discount_value / 100;
        if ($coupon->discount_type === 'percent' && $coupon->max_discount) { $discount = min($discount, (float) $coupon->max_discount); }
        $discount = round(min($discount, $subtotal), 2);

        return ['coupon' => $coupon, 'subtotal' => round($subtotal, 2), 'discount' => $discount, 'final' => round($subtotal - $discount, 2)];
    }

    /** Record that a paid (or free) order used a coupon. Safe to call once per order. */
    public function redeem(PaymentOrder $order, ?string $email = null): void
    {
        if (!$order->coupon_id || CouponRedemption::where('payment_order_id', $order->id)->exists()) { return; }
        CouponRedemption::create([
            'coupon_id' => $order->coupon_id, 'company_id' => $order->company_id, 'payment_order_id' => $order->id,
            'purpose' => $order->purpose, 'email' => $email, 'subtotal' => $order->subtotal ?? $order->amount,
            'discount' => $order->discount, 'final_amount' => $order->amount,
        ]);
    }
}
