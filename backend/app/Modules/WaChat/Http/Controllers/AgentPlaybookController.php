<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaChat\Models\AgentPlaybookTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Company-facing playbook management: pick a superadmin category template, then
 * tweak the cloned playbook (persona, questions, handoff, payment).
 */
class AgentPlaybookController extends Controller
{
    private const RULES = [
        'agent_name'                => 'sometimes|string|max:120',
        'tone'                      => 'sometimes|string|max:120',
        'languages'                 => 'sometimes|nullable|array',
        'languages.*'               => 'string|max:20',
        'system_prompt'             => 'sometimes|nullable|string|max:6000',
        'greeting_new'              => 'sometimes|nullable|string|max:1000',
        'greeting_returning'        => 'sometimes|nullable|string|max:1000',
        'closing_message'           => 'sometimes|nullable|string|max:1000',
        'fallback_transfer_message' => 'sometimes|nullable|string|max:1000',
        'qualification_questions'               => 'sometimes|array',
        'qualification_questions.*.key'         => 'required|string|max:60',
        'qualification_questions.*.question'    => 'required|string|max:500',
        'qualification_questions.*.type'        => 'nullable|string|in:text,choice,number',
        'qualification_questions.*.options'     => 'nullable|array',
        'qualification_questions.*.required'    => 'nullable|boolean',
        'handoff'                   => 'sometimes|nullable|array',
        'escalation'                => 'sometimes|nullable|array',
        'payment'                   => 'sometimes|nullable|array',
        'is_active'                 => 'sometimes|boolean',
    ];

    public function index(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $playbooks = AgentPlaybook::where('company_id', $companyId)
            ->orderByRaw('session_id IS NOT NULL')
            ->get();

        $templates = AgentPlaybookTemplate::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'key', 'name', 'description', 'icon']);

        return response()->json([
            'playbooks' => $playbooks,
            'templates' => $templates,
        ]);
    }

    /** Adopt a category template into a company playbook (company-wide or per-session). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_key' => 'required|string|exists:agent_playbook_templates,key',
            'session_id'   => 'nullable|string|max:190',
        ]);

        $companyId = Auth::user()->company_id;
        $template  = AgentPlaybookTemplate::where('key', $data['template_key'])->firstOrFail();

        $playbook = AgentPlaybook::updateOrCreate(
            ['company_id' => $companyId, 'session_id' => $data['session_id'] ?? null],
            array_merge($template->toPlaybookAttributes(), ['is_active' => true]),
        );

        return response()->json(['message' => 'Playbook created.', 'data' => $playbook], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $playbook = AgentPlaybook::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $playbook->update($request->validate(self::RULES));

        return response()->json(['message' => 'Saved.', 'data' => $playbook]);
    }

    public function toggle(int $id): JsonResponse
    {
        $playbook = AgentPlaybook::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $playbook->update(['is_active' => !$playbook->is_active]);

        return response()->json(['message' => $playbook->is_active ? 'Activated.' : 'Paused.', 'data' => $playbook]);
    }

    public function destroy(int $id): JsonResponse
    {
        $playbook = AgentPlaybook::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $playbook->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
