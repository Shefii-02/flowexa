<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\AgentPlaybookTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Superadmin-only: manage the category playbook templates that companies clone.
 * Routes are behind the `superadmin` middleware.
 */
class AgentPlaybookTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            AgentPlaybookTemplate::orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'            => 'nullable|string|max:60|unique:agent_playbook_templates,key',
            'name'           => 'required|string|max:120',
            'description'    => 'nullable|string|max:500',
            'icon'           => 'nullable|string|max:16',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
            'default_config' => 'required|array',
        ]);

        $data['key'] ??= Str::slug($data['name'], '_');

        $template = AgentPlaybookTemplate::create($data);

        return response()->json(['message' => 'Template created.', 'data' => $template], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = AgentPlaybookTemplate::findOrFail($id);

        $data = $request->validate([
            'name'           => 'sometimes|string|max:120',
            'description'    => 'sometimes|nullable|string|max:500',
            'icon'           => 'sometimes|nullable|string|max:16',
            'is_active'      => 'sometimes|boolean',
            'sort_order'     => 'sometimes|integer|min:0',
            'default_config' => 'sometimes|array',
        ]);

        $template->update($data);

        return response()->json(['message' => 'Saved.', 'data' => $template]);
    }

    public function destroy(int $id): JsonResponse
    {
        AgentPlaybookTemplate::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
