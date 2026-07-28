<?php
namespace App\Modules\Template\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Template\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(private readonly TemplateService $service) {}

    public function index(Request $request): JsonResponse
    {
        $templates = $this->service->list(auth()->user()->company_id, $request->all());
        return response()->json($templates);
    }

    public function show(int $id): JsonResponse
    {
        $t = $this->service->show($id, auth()->user()->company_id);
        return response()->json(['template' => $t]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['required','string','max:100'],
            'wa_template_id'     => ['nullable','string'],
            'wa_phone_number_id' => ['nullable','integer','exists:wa_phone_numbers,id'],
            'category'           => ['required','in:authentication,marketing,utility'],
            'language'           => ['nullable','string','max:10'],
            'body'               => ['required','string'],
            'header'             => ['nullable','string','max:500'],
            'footer'             => ['nullable','string','max:300'],
            'variables'          => ['nullable','array'],
            'status'             => ['nullable','in:pending,approved,rejected'],
        ]);
        $t = $this->service->create(auth()->user()->company_id, $data);
        return response()->json(['message' => 'Template created.', 'template' => $t], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['sometimes','string','max:100'],
            'wa_template_id'     => ['nullable','string'],
            'wa_phone_number_id' => ['nullable','integer','exists:wa_phone_numbers,id'],
            'category'           => ['sometimes','in:authentication,marketing,utility'],
            'language'           => ['nullable','string','max:10'],
            'body'               => ['sometimes','string'],
            'header'             => ['nullable','string','max:500'],
            'footer'             => ['nullable','string','max:300'],
            'variables'          => ['nullable','array'],
            'status'             => ['sometimes','in:pending,approved,rejected'],
        ]);
        $t = $this->service->update($id, auth()->user()->company_id, $data);
        return response()->json(['message' => 'Template updated.', 'template' => $t]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id, auth()->user()->company_id);
        return response()->json(['message' => 'Template deleted.']);
    }

    public function syncFromMeta(int $id): JsonResponse
    {
        $t = $this->service->syncFromMeta($id, auth()->user()->company_id);
        return response()->json(['message' => 'Synced from Meta.', 'template' => $t]);
    }
}
