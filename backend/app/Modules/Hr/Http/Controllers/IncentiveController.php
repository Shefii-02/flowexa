<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\HrIncentive;
use App\Modules\Hr\Models\HrIncentiveRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncentiveController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function guard(): void
    {
        $u = auth()->user();
        abort_unless(
            $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.manage', 'hr.leave.approve']),
            403, 'No incentive permission.',
        );
    }

    // ── Rules: per service / course / product incentive % ────────────────────

    public function rules(): JsonResponse
    {
        return response()->json(['data' => HrIncentiveRule::where('company_id', $this->companyId())
            ->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $this->guard();
        $data = $this->validateRule($request);
        $data['company_id'] = $this->companyId();

        return response()->json(['data' => HrIncentiveRule::create($data)], 201);
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        $this->guard();
        $rule = HrIncentiveRule::where('company_id', $this->companyId())->findOrFail($id);
        $rule->update($this->validateRule($request, true));

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroyRule(int $id): JsonResponse
    {
        $this->guard();
        HrIncentiveRule::where('company_id', $this->companyId())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function validateRule(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name'         => [$req, 'string', 'max:120'],
            'category'     => ['nullable', 'in:service,course,product,other'],
            'kind'         => ['nullable', 'in:percentage,fixed'],
            'percent'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'sort_order'   => ['integer'],
            'is_active'    => ['boolean'],
        ]);
    }

    // ── Ledger ──────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $q = HrIncentive::where('company_id', $this->companyId())
            ->with('user:id,name', 'rule:id,name,category')
            ->when($request->filled('status'), fn ($x) => $x->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn ($x) => $x->where('user_id', $request->integer('user_id')))
            ->when($request->filled('month'), fn ($x) => $x->whereYear('earned_on', substr($request->string('month'), 0, 4))
                ->whereMonth('earned_on', substr($request->string('month'), 5, 2)))
            ->orderByDesc('earned_on');

        // staff see only their own
        $u = auth()->user();
        if (!($u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.manage', 'hr.leave.approve']))) {
            $q->where('user_id', $u->id);
        }

        return response()->json($q->paginate(100));
    }

    /** Manual incentive entry — for conversions not tracked as a deal. */
    public function store(Request $request): JsonResponse
    {
        $this->guard();

        $data = $request->validate([
            'user_id'           => ['required', 'integer', Rule::exists('users', 'id')->where('company_id', $this->companyId())],
            'incentive_rule_id' => ['nullable', 'integer', Rule::exists('hr_incentive_rules', 'id')->where('company_id', $this->companyId())],
            'title'             => ['required', 'string', 'max:160'],
            'base_amount'       => ['nullable', 'numeric', 'min:0'],
            'amount'            => ['nullable', 'numeric', 'min:0'],
            'earned_on'         => ['required', 'date'],
            'note'              => ['nullable', 'string', 'max:500'],
        ]);

        $rule = $data['incentive_rule_id']
            ? HrIncentiveRule::where('company_id', $this->companyId())->find($data['incentive_rule_id'])
            : null;

        $amount = $data['amount']
            ?? ($rule ? $rule->amountFor((float) ($data['base_amount'] ?? 0)) : 0);

        $incentive = HrIncentive::create([
            'company_id'        => $this->companyId(),
            'user_id'           => $data['user_id'],
            'incentive_rule_id' => $rule?->id,
            'source_type'       => 'manual',
            'title'             => $data['title'],
            'base_amount'       => $data['base_amount'] ?? 0,
            'amount'            => round($amount, 2),
            'earned_on'         => $data['earned_on'],
            'status'            => 'pending',
            'note'              => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $incentive->load('user:id,name', 'rule:id,name')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->guard();
        $incentive = HrIncentive::where('company_id', $this->companyId())->findOrFail($id);

        if ($incentive->status === 'paid') {
            abort(422, 'This incentive was already paid out.');
        }

        $incentive->update($request->validate([
            'amount'     => 'sometimes|numeric|min:0',
            'status'     => 'sometimes|in:pending,approved',
            'earned_on'  => 'sometimes|date',
            'note'       => 'nullable|string|max:500',
        ]));

        return response()->json(['data' => $incentive->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->guard();
        $incentive = HrIncentive::where('company_id', $this->companyId())->findOrFail($id);
        abort_if($incentive->status === 'paid', 422, 'Paid incentives cannot be deleted.');
        $incentive->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
