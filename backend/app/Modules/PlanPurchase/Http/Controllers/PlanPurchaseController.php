<?php

namespace App\Modules\PlanPurchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Plan;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanPurchaseController extends Controller
{
    public function __construct(private readonly PlanPurchaseService $svc) {}

    public function publicPlans(): JsonResponse
    {
        return response()->json(['plans' => $this->svc->publicPlans()]);
    }
    public function index(): JsonResponse
    {
        return response()->json(['plans' => $this->svc->activePlans()]);
    }
    public function currentPlan(): JsonResponse
    {
        return response()->json(['current' => $this->svc->currentPlan(auth()->user()->company_id)]);
    }
    public function history(): JsonResponse
    {
        return response()->json(['history' => $this->svc->history(auth()->user()->company_id)]);
    }
    public function addons(): JsonResponse
    {
        return response()->json(['addons' => $this->svc->activeAddons()]);
    }

    /** GET addons/mine — what this company has bought, with status and expiry. */
    public function myAddons(): JsonResponse
    {
        return response()->json(['addons' => $this->svc->companyAddons(auth()->user()->company_id)]);
    }

    /** GET billing/invoices — every completed payment (plans, add-ons, top-ups). */
    public function invoices(): JsonResponse
    {
        return response()->json(['invoices' => $this->svc->invoices(auth()->user()->company_id)]);
    }

    public function previewChange(Request $request): JsonResponse
    {
        $d = $request->validate([
            'plan_id'       => ['required', 'integer', 'exists:plans,id'],
            'duration_type' => ['required', 'in:monthly,3month,6month,yearly,12month'],
        ]);
        return response()->json($this->svc->changePreview(auth()->user()->company_id, $d['plan_id'], $d['duration_type']));
    }

    public function createOrder(Request $request): JsonResponse
    {
        $d = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id'], 'duration_type' => ['required', 'in:monthly,3month,6month,yearly,12month'], 'coupon_code' => ['nullable', 'string', 'max:40']]);
        return response()->json($this->svc->createPlanOrder(auth()->user()->company_id, auth()->id(), $d['plan_id'], $d['duration_type'], $d['coupon_code'] ?? null));
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $d = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string'], 'plan_id' => ['required', 'integer'], 'duration_type' => ['required', 'string']]);
        $cp = $this->svc->verifyPlanPayment(auth()->user()->company_id, $d);
        return response()->json(['message' => "Plan activated: {$cp->plan->name}", 'company_plan' => $cp]);
    }

    public function addonOrder(Request $request, int $addon): JsonResponse
    {
        $d = $request->validate(['billing_cycle' => ['nullable', 'in:monthly,yearly'], 'coupon_code' => ['nullable', 'string', 'max:40']]);
        return response()->json($this->svc->createAddonOrder(auth()->user()->company_id, auth()->id(), $addon, $d['billing_cycle'] ?? null, $d['coupon_code'] ?? null));
    }

    /** POST addons/cart/quote — price a cart (and a coupon) without creating anything. */
    public function cartQuote(Request $request): JsonResponse
    {
        $d = $request->validate(['addon_ids' => ['required', 'array', 'min:1'], 'addon_ids.*' => ['integer'], 'billing_cycle' => ['nullable', 'in:monthly,yearly'], 'coupon_code' => ['nullable', 'string', 'max:40']]);
        $q = $this->svc->quoteCart(auth()->user()->company_id, $d['addon_ids'], $d['billing_cycle'] ?? null, $d['coupon_code'] ?? null);
        return response()->json(['subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'final' => $q['final'], 'coupon' => $q['coupon']?->only(['code', 'name', 'discount_type', 'discount_value']),
            'lines' => $q['lines']->map(fn ($l) => ['addon_id' => $l['addon']->id, 'name' => $l['addon']->name, 'cycle' => $l['cycle'], 'price' => $l['price']])->values()]);
    }

    /** POST addons/cart/create-order — one payment for every add-on in the cart. */
    public function cartOrder(Request $request): JsonResponse
    {
        $d = $request->validate(['addon_ids' => ['required', 'array', 'min:1'], 'addon_ids.*' => ['integer'], 'billing_cycle' => ['nullable', 'in:monthly,yearly'], 'coupon_code' => ['nullable', 'string', 'max:40']]);
        return response()->json($this->svc->createCartOrder(auth()->user()->company_id, auth()->id(), $d['addon_ids'], $d['billing_cycle'] ?? null, $d['coupon_code'] ?? null));
    }

    public function cartVerify(Request $request): JsonResponse
    {
        $d = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string']]);
        return response()->json(['message' => 'Add-ons activated.', 'company_addons' => $this->svc->verifyCartPayment(auth()->user()->company_id, $d)]);
    }

    /** POST plans/coupon/quote — what a coupon takes off a plan purchase. */
    public function planCouponQuote(Request $request): JsonResponse
    {
        $d = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id'], 'duration_type' => ['required', 'in:monthly,3month,6month,yearly,12month'], 'coupon_code' => ['nullable', 'string', 'max:40']]);
        $q = $this->svc->quotePlan(auth()->user()->company_id, $d['plan_id'], $d['duration_type'], $d['coupon_code'] ?? null);
        return response()->json(['subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'final' => $q['final'], 'coupon' => $q['coupon']?->only(['code', 'name', 'discount_type', 'discount_value'])]);
    }

    /** GET modules — the module catalog (labels/icons) for the locked-feature page and add-on cards. */
    public function modules(): JsonResponse
    {
        return response()->json(['modules' => \App\Support\Modules::catalog(), 'groups' => \App\Support\Modules::GROUPS, 'entitlements' => \App\Support\Entitlements::forCompany(auth()->user()->company)]);
    }

    public function verifyAddonPayment(Request $request): JsonResponse
    {
        $d  = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string'], 'addon_id' => ['required', 'integer'], 'billing_cycle' => ['nullable', 'in:monthly,yearly,one_time']]);
        $ca = $this->svc->verifyAddonPayment(auth()->user()->company_id, $d);
        return response()->json(['message' => 'Addon activated.', 'company_addon' => $ca]);
    }

    // SuperAdmin
    public function superAdminPlans(): JsonResponse
    {
        return response()->json(['plans' => $this->svc->superAdminPlans()]);
    }
    public function superAdminAddons(): JsonResponse
    {
        return response()->json(['addons' => $this->svc->superAdminAddons()]);
    }
    public function topupPackages(): JsonResponse
    {
        return response()->json(['packages' => $this->svc->topupPackages()]);
    }

    /** Shared rules for the plan form. $partial: only validate what was sent (updates). */
    private function planRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $int = fn () => ['nullable', 'integer', 'min:0'];
        return [
            'name' => [$req, 'string', 'max:50'],
            'messages_limit' => [$req, 'integer', 'min:0'],
            // Paid plans only — every plan already includes a free trial, so no ₹0 plans.
            'price' => [$req, 'numeric', 'min:1'],
            'price_yearly' => ['nullable', 'numeric', 'min:1'],
            'duration_type' => [$req, 'in:monthly,yearly,3month,6month,12month,custom,unlimited'],
            'duration_months' => ['nullable', 'integer'],
            'alert_before_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'max_users' => $int(), 'max_roles' => $int(), 'max_templates' => $int(),
            'max_phone_numbers' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_wa_sessions' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_campaigns' => $int(), 'max_campaigns_per_month' => $int(), 'max_chat_campaigns' => $int(), 'max_chat_campaigns_per_month' => $int(), 'max_chat_campaign_recipients' => $int(), 'max_campaign_contacts' => $int(),
            'max_surveys' => $int(), 'max_flow_nodes' => $int(),
            'max_contacts' => $int(), 'max_labels' => $int(),
            'max_leads_per_month' => $int(), 'max_lead_categories' => $int(),
            'max_instagram_accounts' => $int(), 'max_instagram_automations' => $int(), 'max_instagram_dms_monthly' => $int(),
            'max_meta_ads_accounts' => $int(), 'max_website_widgets' => $int(), 'max_short_links' => $int(), 'max_sticky_notes_per_user' => $int(), 'max_todos_per_user' => $int(), 'max_media_storage_mb' => $int(), 'wa_chat_credits_per_message' => ['nullable', 'integer', 'min:0', 'max:20'],
            'throttle_per_minute' => [$req, 'integer', 'min:10', 'max:1000'],
            'features' => ['nullable', 'array'], 'features.*' => ['string', 'max:150'],
            'modules' => ['nullable', 'array'], 'modules.*' => ['string', \Illuminate\Validation\Rule::in(\App\Support\Modules::keys())],
            'complimentary' => ['nullable', 'array'], 'complimentary.*.module' => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Support\Modules::keys())],
            'complimentary.*.months' => ['required', 'integer', \Illuminate\Validation\Rule::in(\App\Support\Modules::COMPLIMENTARY_MONTHS)],
            'permissions' => ['nullable', 'array'], 'permissions.*' => ['string', 'max:100'],
            'lead_assign_methods' => ['nullable', 'array'], 'lead_assign_methods.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Modules::ASSIGN_METHODS))],
            'google_sheets_enabled' => ['nullable', 'boolean'], 'google_drive_enabled' => ['nullable', 'boolean'],
            'calendar_enabled' => ['nullable', 'boolean'], 'email_integration_enabled' => ['nullable', 'boolean'],
            'apply_to_existing' => ['nullable', 'boolean'],
            'billing_options' => ['nullable', 'array'], 'billing_options.3month' => ['nullable', 'numeric', 'min:0', 'max:90'], 'billing_options.6month' => ['nullable', 'numeric', 'min:0', 'max:90'], 'billing_options.yearly' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'is_active' => ['nullable', 'boolean'], 'is_public' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean'], 'is_popular' => ['nullable', 'boolean'], 'sort_order' => ['nullable', 'integer'],
        ];
    }

    /** The form's "archived" switch becomes archived_at; an archived plan is also never public. */
    private function visibility(array $d): array
    {
        if (array_key_exists('archived', $d)) {
            $d['archived_at'] = $d['archived'] ? now() : null;
            if ($d['archived']) { $d['is_public'] = false; }
            unset($d['archived']);
        }
        return $d;
    }

    public function superAdminCreatePlan(Request $request): JsonResponse
    {
        $d = $request->validate($this->planRules(false));
        // A brand-new plan lists its modules explicitly (an empty list = none), unlike old plans where NULL meant "all".
        $d['modules'] = $d['modules'] ?? [];
        return response()->json(['plan' => Plan::create($this->visibility($d))], 201);
    }

    public function superAdminUpdatePlan(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $d = $request->validate($this->planRules(true));
        $applyToExisting = $d['apply_to_existing'] ?? true;
        unset($d['apply_to_existing']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($plan, $d, $applyToExisting) {
            // "No" = companies already on this plan keep exactly what they bought: they are moved onto a hidden copy of the plan as it is now,
            // then the live plan is edited, so only new purchases get the changes.
            if (!$applyToExisting && $plan->companies()->exists()) {
                $legacy = $plan->replicate();
                $legacy->archived_at = now();
                $legacy->is_public = false;
                $legacy->save();
                \App\Models\Company::where('plan_id', $plan->id)->update(['plan_id' => $legacy->id]);
                \App\Models\CompanyPlan::where('plan_id', $plan->id)->where('status', 'active')->update(['plan_id' => $legacy->id]);
            }
            $plan->update($this->visibility($d));
        });
        \App\Support\Entitlements::forget();
        return response()->json(['plan' => $plan->fresh(), 'applied_to_existing' => (bool) $applyToExisting]);
    }

    /** Soft delete: the plan disappears from every pricing list, but companies already on it keep working until they change plan. */
    public function superAdminDeletePlan(int $id): JsonResponse
    {
        $plan = Plan::withCount('companies')->findOrFail($id);
        $plan->delete();
        return response()->json(['message' => $plan->companies_count > 0
            ? "Plan removed from sale. {$plan->companies_count} compan" . ($plan->companies_count === 1 ? 'y keeps' : 'ies keep') . " it until they change plan."
            : 'Plan deleted.']);
    }

    public function superAdminRestorePlan(int $id): JsonResponse
    {
        $plan = Plan::onlyTrashed()->findOrFail($id);
        $plan->restore();
        return response()->json(['plan' => $plan->fresh()]);
    }

    /** GET superadmin/plans/catalog — modules, assign methods and limit fields the plan form is built from. */
    public function planCatalog(): JsonResponse
    {
        return response()->json([
            'modules' => \App\Support\Modules::catalog(), 'groups' => \App\Support\Modules::GROUPS,
            'permissions' => \App\Support\Modules::permissionCatalog(), 'complimentary_months' => \App\Support\Modules::COMPLIMENTARY_MONTHS,
            'assign_methods' => collect(\App\Support\Modules::ASSIGN_METHODS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    // ── Superadmin: upgrade / change a company's plan with online or manual payment ──

    private function upgradeRules(): array
    {
        return ['plan_id' => ['required', 'integer', 'exists:plans,id'], 'duration_type' => ['required', 'in:monthly,3month,6month,yearly,12month'], 'coupon_code' => ['nullable', 'string', 'max:40']];
    }

    /** POST superadmin/companies/{company}/plan-upgrade/quote — change type, price, coupon and any usage that blocks the switch. */
    public function saUpgradeQuote(Request $request, int $company): JsonResponse
    {
        $d = $request->validate($this->upgradeRules());
        $preview = $this->svc->changePreview($company, $d['plan_id'], $d['duration_type']);
        $q = $this->svc->quotePlan($company, $d['plan_id'], $d['duration_type'], $d['coupon_code'] ?? null);
        return response()->json($preview + ['subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'final' => $q['final'], 'coupon' => $q['coupon']?->only(['code', 'name', 'discount_type', 'discount_value'])]);
    }

    /** POST superadmin/companies/{company}/plan-upgrade/order — Razorpay order (or instant activation when a coupon makes it free). */
    public function saUpgradeOrder(Request $request, int $company): JsonResponse
    {
        $d = $request->validate($this->upgradeRules());
        return response()->json($this->svc->createPlanOrder($company, auth()->id(), $d['plan_id'], $d['duration_type'], $d['coupon_code'] ?? null, true));
    }

    /** POST superadmin/companies/{company}/plan-upgrade/verify — confirm the Razorpay payment and activate. */
    public function saUpgradeVerify(Request $request, int $company): JsonResponse
    {
        $d = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string'], 'plan_id' => ['required', 'integer'], 'duration_type' => ['required', 'string']]);
        $cp = $this->svc->verifyPlanPayment($company, $d);
        return response()->json(['message' => "Plan activated: {$cp->plan->name}", 'company_plan' => $cp]);
    }

    /** POST superadmin/companies/{company}/plan-upgrade/manual — cash / bank / UPI received outside the gateway. */
    public function saUpgradeManual(Request $request, int $company): JsonResponse
    {
        $d = $request->validate($this->upgradeRules() + ['method' => ['required', 'string', 'max:30'], 'reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:300'], 'amount' => ['nullable', 'numeric', 'min:0']]);
        $cp = $this->svc->manualPlanPurchase($company, $d['plan_id'], $d['duration_type'], $d['method'], $d['reference'] ?? null, $d['note'] ?? null, $d['coupon_code'] ?? null, isset($d['amount']) ? (float) $d['amount'] : null);
        return response()->json(['message' => "Plan activated: {$cp->plan->name}", 'company_plan' => $cp]);
    }

    public function assignCustomPlan(Request $request): JsonResponse
    {
        $d  = $request->validate(['company_id' => ['required', 'integer'], 'plan_id' => ['required', 'integer'], 'expires_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
        $cp = $this->svc->assignCustomPlan($d['company_id'], $d['plan_id'], $d['expires_at'] ?? null, $d['notes'] ?? null);
        return response()->json(['message' => 'Custom plan assigned.', 'company_plan' => $cp]);
    }

    public function createTopupPackage(Request $request): JsonResponse
    {
        $d = $request->validate(['messages' => ['required', 'integer', 'min:10'], 'price' => ['required', 'numeric', 'min:1'], 'label' => ['nullable', 'string', 'max:100'], 'is_popular' => ['nullable', 'boolean'], 'sort_order' => ['nullable', 'integer']]);
        return response()->json(['package' => $this->svc->createTopupPackage($d)], 201);
    }

    public function updateTopupPackage(Request $request, int $id): JsonResponse
    {
        return response()->json(['package' => $this->svc->updateTopupPackage($id, $request->all())]);
    }

    public function deleteTopupPackage(int $id): JsonResponse
    {
        $this->svc->deleteTopupPackage($id);
        return response()->json(['message' => 'Package deleted.']);
    }

    /** GET superadmin/addons/resources — the limits a "limit" add-on can raise (key, label, whether it resets monthly). */
    public function addonResources(): JsonResponse
    {
        $rows = collect(\App\Support\PlanLimits::REGISTRY)->map(fn ($r, $key) => ['key' => $key, 'label' => $r[0], 'kind' => $r[3]])->values();
        $extra = collect(\App\Support\PlanLimits::EXTRA_RESOURCES)->map(fn ($r, $key) => ['key' => $key, 'label' => $r[0], 'kind' => $r[1]])->values();
        return response()->json(['resources' => $rows->concat($extra)->values()]);
    }

    public function superAdminCreateAddon(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:100'], 'slug' => ['nullable', 'string', 'max:110'], 'description' => ['nullable', 'string'], 'type' => ['required', 'in:feature,message_pack,storage,limit,module'], 'price' => ['required', 'numeric', 'min:0'], 'price_yearly' => ['nullable', 'numeric', 'min:0'], 'billing_cycle' => ['required', 'in:monthly,yearly,one_time'], 'config' => ['nullable', 'array'], 'is_active' => ['nullable', 'boolean']]);
        // A limit add-on raises one plan limit (e.g. staff users): it must name which one and by how much.
        if ($d['type'] === 'limit') {
            $request->validate([
                'config.resource' => ['required', 'string', \Illuminate\Validation\Rule::in(array_merge(array_keys(\App\Support\PlanLimits::REGISTRY), array_keys(\App\Support\PlanLimits::EXTRA_RESOURCES)))],
                'config.amount'   => ['required', 'integer', 'min:1', 'max:100000'],
            ]);
        }
        if ($d['type'] === 'module') {
            $request->validate(['config.module' => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Support\Modules::keys())]]);
        }
        $this->validatePackConfig($request, $d['type']);
        return response()->json(['addon' => $this->svc->createAddon($d)], 201);
    }

    /** A storage add-on must say how many MB it adds; a message pack how many messages. */
    private function validatePackConfig(Request $request, ?string $type): void
    {
        if ($type === 'storage') { $request->validate(['config.mb' => ['required', 'integer', 'min:1', 'max:10000000']]); }
        if ($type === 'message_pack') { $request->validate(['config.messages' => ['required', 'integer', 'min:1', 'max:100000000']]); }
    }

    public function superAdminUpdateAddon(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'], 'slug' => ['sometimes', 'nullable', 'string', 'max:110'], 'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'in:feature,message_pack,storage,limit,module'], 'price' => ['sometimes', 'numeric', 'min:0'], 'price_yearly' => ['nullable', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly,one_time'], 'config' => ['nullable', 'array'], 'is_active' => ['nullable', 'boolean'],
            'apply_to_existing' => ['nullable', 'boolean'],
        ]);
        if (($d['type'] ?? null) === 'limit') {
            $request->validate(['config.resource' => ['required', 'string', \Illuminate\Validation\Rule::in(array_merge(array_keys(\App\Support\PlanLimits::REGISTRY), array_keys(\App\Support\PlanLimits::EXTRA_RESOURCES)))], 'config.amount' => ['required', 'integer', 'min:1', 'max:100000']]);
        }
        if (($d['type'] ?? null) === 'module') {
            $request->validate(['config.module' => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Support\Modules::keys())]]);
        }
        $this->validatePackConfig($request, $d['type'] ?? null);
        $apply = $d['apply_to_existing'] ?? true;
        unset($d['apply_to_existing']);
        if (empty($d['slug'])) { unset($d['slug']); }
        return response()->json(['addon' => $this->svc->updateAddon($id, $d, (bool) $apply)]);
    }

    /** DELETE superadmin/addons/{id} — retire an add-on; companies that already bought it keep it until it expires. */
    public function superAdminDeleteAddon(int $id): JsonResponse
    {
        \App\Models\Addon::findOrFail($id)->delete();
        return response()->json(['message' => 'Add-on removed. Companies that already bought it keep it until it expires.']);
    }
}
