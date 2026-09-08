<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CrmDeal;
use App\Modules\Hr\Models\HrIncentive;
use App\Modules\Hr\Models\HrIncentiveRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function scoped()
    {
        return CrmDeal::where('company_id', $this->companyId());
    }

    public function index(Request $request): JsonResponse
    {
        $deals = $this->scoped()
            ->with(['contact:id,name,phone', 'owner:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('stage'), fn ($q) => $q->where('stage', $request->string('stage')))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('owner_id', $request->integer('owner_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%' . $request->string('q') . '%'))
            ->orderBy('sort_order')->orderByDesc('id')
            ->get();

        return response()->json(['data' => $deals]);
    }

    /** Kanban: deals grouped by stage + per-stage totals. */
    public function board(): JsonResponse
    {
        $deals = $this->scoped()->with(['contact:id,name,phone', 'owner:id,name'])
            ->orderBy('sort_order')->orderByDesc('id')->get()->groupBy('stage');

        $columns = collect(CrmDeal::STAGES)->map(fn ($stage) => [
            'stage' => $stage,
            'count' => $deals->get($stage)?->count() ?? 0,
            'value' => (float) ($deals->get($stage)?->sum('value') ?? 0),
            'deals' => $deals->get($stage)?->values() ?? [],
        ]);

        return response()->json(['data' => $columns]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $this->companyId();
        $data['status'] = CrmDeal::statusForStage($data['stage'] ?? 'new');
        if (in_array($data['status'], ['won', 'lost'], true)) {
            $data['closed_at'] = now();
        }

        $deal = CrmDeal::create($data);
        $this->awardIncentiveIfWon($deal, false);

        return response()->json(['data' => $deal->load('contact:id,name,phone', 'owner:id,name')], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->scoped()->with('contact', 'owner:id,name', 'tasks')->findOrFail($id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $deal = $this->scoped()->findOrFail($id);
        $data = $this->validated($request, isUpdate: true);

        $wasWon = $deal->status === 'won';

        if (array_key_exists('stage', $data)) {
            $data['status'] = CrmDeal::statusForStage($data['stage']);
            $data['closed_at'] = in_array($data['status'], ['won', 'lost'], true) ? ($deal->closed_at ?? now()) : null;
        }

        $deal->update($data);
        $this->awardIncentiveIfWon($deal->fresh(), $wasWon);

        return response()->json(['data' => $deal->fresh()->load('contact:id,name,phone', 'owner:id,name')]);
    }

    /** PATCH /crm/deals/{id}/stage — used by the Kanban move. */
    public function moveStage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'stage'       => ['required', Rule::in(CrmDeal::STAGES)],
            'lost_reason' => ['nullable', 'string', 'max:200'],
        ]);
        $deal = $this->scoped()->findOrFail($id);

        $wasWon = $deal->status === 'won';
        $status = CrmDeal::statusForStage($data['stage']);
        $deal->update([
            'stage'       => $data['stage'],
            'status'      => $status,
            'lost_reason' => $data['stage'] === 'lost' ? ($data['lost_reason'] ?? $deal->lost_reason) : null,
            'closed_at'   => in_array($status, ['won', 'lost'], true) ? ($deal->closed_at ?? now()) : null,
        ]);
        $this->awardIncentiveIfWon($deal->fresh(), $wasWon);

        return response()->json(['data' => $deal->fresh()->load('contact:id,name,phone', 'owner:id,name')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->scoped()->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * When a deal first becomes "won" and carries an incentive rule + owner +
     * value, drop a pending incentive into the HR ledger for that staff member.
     */
    private function awardIncentiveIfWon(CrmDeal $deal, bool $wasWon): void
    {
        if ($wasWon || $deal->status !== 'won' || !$deal->incentive_rule_id || !$deal->owner_id || (float) $deal->value <= 0) {
            return;
        }
        if (HrIncentive::where('source_type', 'deal')->where('source_id', $deal->id)->exists()) {
            return;
        }

        $rule = HrIncentiveRule::where('company_id', $deal->company_id)->find($deal->incentive_rule_id);
        if (!$rule) {
            return;
        }

        HrIncentive::create([
            'company_id'        => $deal->company_id,
            'user_id'           => $deal->owner_id,
            'incentive_rule_id' => $rule->id,
            'source_type'       => 'deal',
            'source_id'         => $deal->id,
            'title'             => "{$deal->title} — {$rule->name}",
            'base_amount'       => (float) $deal->value,
            'amount'            => $rule->amountFor((float) $deal->value),
            'earned_on'         => now()->toDateString(),
            'status'            => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'title'               => [$req, 'string', 'max:200'],
            'incentive_rule_id'   => ['nullable', 'integer', Rule::exists('hr_incentive_rules', 'id')->where('company_id', $this->companyId())],
            'contact_id'          => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->companyId())],
            'owner_id'            => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $this->companyId())],
            'value'               => ['nullable', 'numeric', 'min:0'],
            'currency'            => ['nullable', 'string', 'size:3'],
            'stage'               => ['nullable', Rule::in(CrmDeal::STAGES)],
            'expected_close_date' => ['nullable', 'date'],
            'source'              => ['nullable', 'string', 'max:60'],
            'lost_reason'         => ['nullable', 'string', 'max:200'],
            'notes'               => ['nullable', 'string'],
        ]);
    }
}
