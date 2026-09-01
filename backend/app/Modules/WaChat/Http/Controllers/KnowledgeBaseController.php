<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\AiKnowledgeBase;
use App\Modules\WaChat\Models\AiKnowledgeChunk;
use App\Modules\WaChat\Jobs\GenerateKnowledgeEmbeddings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class KnowledgeBaseController extends Controller
{
    public function index(): JsonResponse
    {
        $items = AiKnowledgeBase::where('company_id', Auth::user()->company_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'description'   => 'nullable|string|max:500',
            'document_type' => 'required|in:text,url,file',
            'raw_content'   => 'required_if:document_type,text|nullable|string',
            'source_url'    => 'required_if:document_type,url|nullable|url',
        ]);

        $companyId = Auth::user()->company_id;

        $kb = AiKnowledgeBase::create(array_merge($data, [
            'company_id' => $companyId,
            'status'     => 'pending',
        ]));

        dispatch(new GenerateKnowledgeEmbeddings($kb->id));

        return response()->json($kb, 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:120',
            'file'  => 'required|file|mimes:txt,pdf,doc,docx|max:5120',
        ]);

        $companyId = Auth::user()->company_id;
        $path      = $request->file('file')->store("knowledge/{$companyId}", 'public');

        $kb = AiKnowledgeBase::create([
            'company_id'    => $companyId,
            'name'          => $request->name,
            'document_type' => 'file',
            'file_path'     => $path,
            'status'        => 'pending',
        ]);

        dispatch(new GenerateKnowledgeEmbeddings($kb->id));

        return response()->json($kb, 201);
    }

    public function show(int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $chunks = AiKnowledgeChunk::where('knowledge_base_id', $id)
            ->select('id', 'chunk_index', 'content', 'created_at')
            ->orderBy('chunk_index')
            ->get();

        return response()->json(['kb' => $kb, 'chunks' => $chunks]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
            'raw_content' => 'sometimes|string',
            'source_url'  => 'nullable|url',
        ]);

        $kb->update($data);

        // Re-generate embeddings if content changed
        if (isset($data['raw_content']) || isset($data['source_url'])) {
            $kb->update(['status' => 'pending']);
            dispatch(new GenerateKnowledgeEmbeddings($kb->id));
        }

        return response()->json($kb);
    }

    public function destroy(int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($kb->file_path) {
            Storage::disk('public')->delete($kb->file_path);
        }

        $kb->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function reprocess(int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $kb->update(['status' => 'pending', 'error_message' => null]);
        dispatch(new GenerateKnowledgeEmbeddings($kb->id));

        return response()->json(['message' => 'Reprocessing started.']);
    }
}
