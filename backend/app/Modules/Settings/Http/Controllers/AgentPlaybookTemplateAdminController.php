<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\AgentPlaybookTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SuperAdmin CRUD over the global AgentPlaybookTemplate library — the per-industry
 * conversational agent presets (LMS, Real Estate, Health Clinic, Software Company,
 * Construction, Services…) that a company clones into its own AgentPlaybook at signup
 * (see CompanyStarterKit) and can then customize freely. Adding a new industry here is
 * how the platform grows to cover a new business vertical without a code deploy.
 */
class AgentPlaybookTemplateAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            AgentPlaybookTemplate::orderBy('sort_order')->get()
        );
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(AgentPlaybookTemplate::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $template = AgentPlaybookTemplate::create($data);

        return response()->json(['message' => 'Template created.', 'data' => $template], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = AgentPlaybookTemplate::findOrFail($id);
        $template->update($this->validated($request, false, $id));

        return response()->json(['message' => 'Template saved.', 'data' => $template->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $template = AgentPlaybookTemplate::findOrFail($id);

        // Companies that already cloned this template keep their own independent
        // AgentPlaybook row (template_key is just a label on it) — deleting the shared
        // template can never retroactively break an existing company's live agent.
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function validated(Request $request, bool $creating, ?int $ignoreId = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $keyUnique = 'unique:agent_playbook_templates,key' . ($ignoreId ? ",{$ignoreId}" : '');

        return $request->validate([
            'key'         => [$req, 'string', 'max:60', 'alpha_dash', $keyUnique],
            'name'        => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon'        => ['sometimes', 'nullable', 'string', 'max:10'],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['sometimes', 'integer'],

            'default_config'                             => [$req, 'array'],
            'default_config.agent_name'                  => ['sometimes', 'nullable', 'string', 'max:120'],
            'default_config.tone'                         => ['sometimes', 'nullable', 'string', 'max:120'],
            'default_config.languages'                    => ['sometimes', 'nullable', 'array'],
            'default_config.system_prompt'                 => ['sometimes', 'nullable', 'string', 'max:6000'],
            'default_config.greeting_new'                  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_config.greeting_returning'            => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_config.closing_message'               => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_config.fallback_transfer_message'     => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_config.qualification_questions'                    => ['sometimes', 'array'],
            'default_config.qualification_questions.*.key'              => ['required_with:default_config.qualification_questions', 'string', 'max:60'],
            'default_config.qualification_questions.*.question'         => ['required_with:default_config.qualification_questions', 'string', 'max:500'],
            'default_config.qualification_questions.*.type'             => ['nullable', 'string', 'in:text,choice,number,phone'],
            'default_config.qualification_questions.*.options'          => ['nullable', 'array'],
            'default_config.qualification_questions.*.required'         => ['nullable', 'boolean'],
            'default_config.handoff'    => ['sometimes', 'nullable', 'array'],
            'default_config.escalation' => ['sometimes', 'nullable', 'array'],
            'default_config.payment'    => ['sometimes', 'nullable', 'array'],
        ]);
    }
}
