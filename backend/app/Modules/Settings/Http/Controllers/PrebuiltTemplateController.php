<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PrebuiltTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Superadmin CRUD over the shared WhatsApp template library (`prebuilt_templates`).
 * Every row is editable here, including the rows installed by
 * {@see \Database\Seeders\PrebuiltTemplateSeeder}. Companies only read this
 * library (filtered by `type`) when picking a template for an API config.
 */
class PrebuiltTemplateController extends Controller
{
    /** GET /superadmin/prebuilt-templates */
    public function index(Request $request): JsonResponse
    {
        $templates = PrebuiltTemplate::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('language'), fn ($q) => $q->where('language', $request->string('language')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim((string) $request->string('search'));
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('content', 'like', "%{$s}%"));
            })
            ->orderBy('type')
            ->orderBy('name')
            ->orderBy('language')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json($templates);
    }

    /** POST /superadmin/prebuilt-templates */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['variables'] = PrebuiltTemplate::extractVariables($data['content']);

        $template = PrebuiltTemplate::create($data);

        return response()->json(['message' => 'Template created.', 'data' => $template], 201);
    }

    /** PATCH /superadmin/prebuilt-templates/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = PrebuiltTemplate::findOrFail($id);
        $data = $this->validated($request, $id);

        if (array_key_exists('content', $data)) {
            $data['variables'] = PrebuiltTemplate::extractVariables($data['content']);
        }

        $template->update($data);

        return response()->json(['message' => 'Template updated.', 'data' => $template]);
    }

    /** DELETE /superadmin/prebuilt-templates/{id} */
    public function destroy(int $id): JsonResponse
    {
        PrebuiltTemplate::findOrFail($id)->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * `type` stays a free-form string in the schema, but the UI only offers
     * these three, so validate against them while leaving room to widen later.
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $required = $ignoreId === null ? 'required' : 'sometimes';

        return $request->validate([
            'name'     => [
                $required, 'string', 'max:150',
                Rule::unique('prebuilt_templates', 'name')
                    ->where(fn ($q) => $q->where('language', $request->input('language', 'en')))
                    ->ignore($ignoreId),
            ],
            'type'     => [$required, 'string', 'max:30'],
            'content'  => [$required, 'string', 'max:4000'],
            'language' => ['sometimes', 'string', 'max:10'],
            'status'   => ['sometimes', 'in:active,inactive'],
        ]);
    }
}
