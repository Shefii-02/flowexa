<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaCloud\Models\WaCloudAutomationLog;
use App\Modules\WaCloud\Models\WaCloudAutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD + logs for WA Cloud automation rules (own tables, own endpoints —
 * separate from the open-wa /wa-agent/automations controller).
 */
class WaCloudAutomationController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    public function index(): JsonResponse
    {
        $rules = WaCloudAutomationRule::where('company_id', $this->companyId())
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $this->companyId();

        $rule = WaCloudAutomationRule::create($data);

        return response()->json($rule, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->find($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = $this->find($id);
        $rule->update($this->validated($request, isUpdate: true));

        return response()->json($rule);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $rule = $this->find($id);
        $rule->update(['is_active' => !$rule->is_active]);

        return response()->json($rule);
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = WaCloudAutomationLog::where('company_id', $this->companyId())
            ->when($request->filled('rule_id'), fn ($q) => $q->where('rule_id', $request->integer('rule_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('wa_phone_number_id'), fn ($q) => $q->where('wa_phone_number_id', $request->integer('wa_phone_number_id')))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function find(int $id): WaCloudAutomationRule
    {
        return WaCloudAutomationRule::where('company_id', $this->companyId())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'wa_phone_number_id' => ['nullable', 'integer', Rule::exists('wa_phone_numbers', 'id')->where('company_id', $this->companyId())],
            'rule_type'          => [$req, Rule::in(WaCloudAutomationRule::RULE_TYPES)],
            'name'               => [$req, 'string', 'max:150'],
            'conditions'         => ['nullable', 'array'],
            'actions'            => [$req, 'array', 'min:1'],
            'keywords'           => ['nullable', 'array'],
            'priority'           => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active'          => ['boolean'],
            'schedule_start'     => ['nullable', 'date_format:H:i'],
            'schedule_end'       => ['nullable', 'date_format:H:i'],
            'schedule_days'      => ['nullable', 'array'],
            'delay_hours'        => ['nullable', 'integer', 'min:1'],
            'inactivity_hours'   => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
