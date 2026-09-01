<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WaChatTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaChatTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = WaChatTemplate::where('company_id', auth()->user()->company_id)
            ->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:150',
            'category'       => 'required|string|max:100',
            'language'       => 'nullable|string|max:10',
            'header_type'    => 'nullable|in:none,text,image,video,document',
            'header_content' => 'nullable|string',
            'body'           => 'required|string',
            'footer'         => 'nullable|string|max:500',
            'buttons'        => 'nullable|array',
            'media_blocks'   => 'nullable|array',
            'status'         => 'nullable|in:draft,active,archived',
        ]);

        $template = WaChatTemplate::create(array_merge($data, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
        ]));

        return response()->json(['message' => 'Template created.', 'data' => $template], 201);
    }

    public function show(int $id): JsonResponse
    {
        $template = WaChatTemplate::where('company_id', auth()->user()->company_id)->findOrFail($id);
        return response()->json(['data' => $template]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = WaChatTemplate::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:150',
            'category'       => 'sometimes|string|max:100',
            'language'       => 'nullable|string|max:10',
            'header_type'    => 'nullable|in:none,text,image,video,document',
            'header_content' => 'nullable|string',
            'body'           => 'sometimes|string',
            'footer'         => 'nullable|string|max:500',
            'buttons'        => 'nullable|array',
            'media_blocks'   => 'nullable|array',
            'status'         => 'nullable|in:draft,active,archived',
        ]);
        $template->update($data);
        return response()->json(['message' => 'Template updated.', 'data' => $template]);
    }

    public function destroy(int $id): JsonResponse
    {
        WaChatTemplate::where('company_id', auth()->user()->company_id)->findOrFail($id)->delete();
        return response()->json(['message' => 'Template deleted.']);
    }
}
