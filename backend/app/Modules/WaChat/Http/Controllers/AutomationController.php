<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\AutomationRule;
use App\Modules\WaChat\Models\AutomationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $rules = AutomationRule::where('company_id', $companyId)
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id'       => 'required|string',
            'rule_type'        => 'required|in:welcome_message,out_of_office,lead_qualifier,follow_up_reminder,follow_up_agent,keyword_trigger,inactivity_trigger',
            'name'             => 'required|string|max:120',
            'conditions'       => 'nullable|array',
            'actions'          => 'required|array',
            'keywords'         => 'nullable|array',
            'priority'         => 'integer|min:0|max:100',
            'is_active'        => 'boolean',
            'schedule_start'   => 'nullable|date_format:H:i',
            'schedule_end'     => 'nullable|date_format:H:i',
            'schedule_days'    => 'nullable|array',
            'delay_hours'      => 'nullable|integer|min:1',
            'inactivity_hours' => 'nullable|integer|min:1',
        ]);

        $rule = AutomationRule::create(array_merge($data, [
            'company_id' => Auth::user()->company_id,
        ]));

        return response()->json($rule, 201);
    }

    public function show(int $id): JsonResponse
    {
        $rule = AutomationRule::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        return response()->json($rule);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $data = $request->validate([
            'session_id'       => 'sometimes|string',
            'rule_type'        => 'sometimes|in:welcome_message,out_of_office,lead_qualifier,follow_up_reminder,follow_up_agent,keyword_trigger,inactivity_trigger',
            'name'             => 'sometimes|string|max:120',
            'conditions'       => 'nullable|array',
            'actions'          => 'sometimes|array',
            'keywords'         => 'nullable|array',
            'priority'         => 'integer|min:0|max:100',
            'is_active'        => 'boolean',
            'schedule_start'   => 'nullable|date_format:H:i',
            'schedule_end'     => 'nullable|date_format:H:i',
            'schedule_days'    => 'nullable|array',
            'delay_hours'      => 'nullable|integer|min:1',
            'inactivity_hours' => 'nullable|integer|min:1',
        ]);

        $rule->update($data);

        return response()->json($rule);
    }

    public function destroy(int $id): JsonResponse
    {
        $rule = AutomationRule::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $rule->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function logs(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $logs = AutomationLog::where('company_id', $companyId)
            ->when($request->rule_id,  fn($q) => $q->where('rule_id', $request->rule_id))
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->session_id, fn($q) => $q->where('session_id', $request->session_id))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $rule = AutomationRule::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $rule->update(['is_active' => !$rule->is_active]);

        return response()->json($rule);
    }
}
