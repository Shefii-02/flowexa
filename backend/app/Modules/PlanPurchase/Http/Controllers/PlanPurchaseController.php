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
        $d = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id'], 'duration_type' => ['required', 'in:monthly,3month,6month,yearly,12month']]);
        return response()->json($this->svc->createPlanOrder(auth()->user()->company_id, auth()->id(), $d['plan_id'], $d['duration_type']));
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $d = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string'], 'plan_id' => ['required', 'integer'], 'duration_type' => ['required', 'string']]);
        $cp = $this->svc->verifyPlanPayment(auth()->user()->company_id, $d);
        return response()->json(['message' => "Plan activated: {$cp->plan->name}", 'company_plan' => $cp]);
    }

    public function addonOrder(Request $request, int $addon): JsonResponse
    {
        $cycle = $request->validate(['billing_cycle' => ['nullable', 'in:monthly,yearly']])['billing_cycle'] ?? null;
        return response()->json($this->svc->createAddonOrder(auth()->user()->company_id, auth()->id(), $addon, $cycle));
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

    public function superAdminCreatePlan(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'messages_limit' => ['required', 'integer'],
            // Paid plans only — every plan already includes a free trial, so no ₹0 plans.
            'price' => ['required', 'numeric', 'min:1'],
            'price_yearly' => ['nullable', 'numeric', 'min:1'],
            'duration_type' => ['required', 'in:monthly,yearly,3month,6month,12month,custom,unlimited'],
            'duration_months' => ['nullable', 'integer'],
            'alert_before_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'max_users' => ['nullable', 'integer'],
            'max_templates' => ['nullable', 'integer'],
            'max_phone_numbers' => ['required', 'integer', 'min:0', 'max:20'],
            'max_wa_sessions' => ['nullable', 'integer', 'min:0', 'max:20'],
            'max_campaigns' => ['nullable', 'integer'],
            'max_contacts' => ['nullable', 'integer'],
            'max_labels' => ['nullable', 'integer'],
            'max_flow_nodes' => ['nullable', 'integer'],
            'max_campaign_contacts' => ['nullable', 'integer'],
            'throttle_per_minute' => ['required', 'integer', 'min:10', 'max:1000'],
            'features' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        return response()->json(['plan' => Plan::create($d)], 201);
    }

    public function superAdminUpdatePlan(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $plan->update($request->all());
        return response()->json(['plan' => $plan->fresh()]);
    }

    public function superAdminDeletePlan(int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        if ($plan->companies()->count() > 0) return response()->json(['message' => 'Cannot delete plan with active companies.'], 422);
        $plan->delete();
        return response()->json(['message' => 'Plan deleted.']);
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
        return response()->json(['resources' => collect(\App\Support\PlanLimits::REGISTRY)->map(fn ($r, $key) => ['key' => $key, 'label' => $r[0], 'kind' => $r[3]])->values()]);
    }

    public function superAdminCreateAddon(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'string', 'max:110'], 'description' => ['nullable', 'string'], 'type' => ['required', 'in:feature,message_pack,storage,limit'], 'price' => ['required', 'numeric', 'min:0'], 'price_yearly' => ['nullable', 'numeric', 'min:0'], 'billing_cycle' => ['required', 'in:monthly,yearly,one_time'], 'config' => ['nullable', 'array'], 'is_active' => ['nullable', 'boolean']]);
        // A limit add-on raises one plan limit (e.g. staff users): it must name which one and by how much.
        if ($d['type'] === 'limit') {
            $request->validate([
                'config.resource' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\PlanLimits::REGISTRY))],
                'config.amount'   => ['required', 'integer', 'min:1', 'max:100000'],
            ]);
        }
        return response()->json(['addon' => $this->svc->createAddon($d)], 201);
    }

    public function superAdminUpdateAddon(Request $request, int $id): JsonResponse
    {
        return response()->json(['addon' => $this->svc->updateAddon($id, $request->all())]);
    }
}
