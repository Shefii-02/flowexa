<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\AiPipeline;
use App\Modules\WaChat\Models\AiPipelineRun;
use App\Modules\WaChat\Jobs\RunPipeline;
use App\Modules\WaChat\Services\PipelineRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PipelineController extends Controller
{
    public function index(): JsonResponse
    {
        $pipelines = AiPipeline::where('company_id', Auth::user()->company_id)
            ->withCount('runs')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($pipelines);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'description'    => 'nullable|string|max:500',
            'trigger_type'   => 'required|in:message,webhook,cron,manual',
            'trigger_config' => 'nullable|array',
            'steps'          => 'required|array|min:1',
            'is_active'      => 'boolean',
        ]);

        $pipeline = AiPipeline::create(array_merge($data, [
            'company_id' => Auth::user()->company_id,
        ]));

        return response()->json($pipeline, 201);
    }

    public function show(int $id): JsonResponse
    {
        $pipeline = AiPipeline::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $runs = AiPipelineRun::where('pipeline_id', $id)
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();

        return response()->json(['pipeline' => $pipeline, 'runs' => $runs]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $pipeline = AiPipeline::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $data = $request->validate([
            'name'           => 'sometimes|string|max:120',
            'description'    => 'nullable|string|max:500',
            'trigger_type'   => 'sometimes|in:message,webhook,cron,manual',
            'trigger_config' => 'nullable|array',
            'steps'          => 'sometimes|array|min:1',
            'is_active'      => 'boolean',
        ]);

        $pipeline->update($data);

        return response()->json($pipeline);
    }

    public function destroy(int $id): JsonResponse
    {
        $pipeline = AiPipeline::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $pipeline->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function run(Request $request, int $id, PipelineRunner $runner): JsonResponse
    {
        $pipeline = AiPipeline::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $triggerData = $request->input('trigger_data', []);
        $triggerData['company_id'] = $pipeline->company_id;

        dispatch(new RunPipeline($pipeline->id, $triggerData, 'manual'));

        return response()->json(['message' => 'Pipeline queued.']);
    }

    public function runs(int $id): JsonResponse
    {
        $pipeline = AiPipeline::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $runs = AiPipelineRun::where('pipeline_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return response()->json($runs);
    }
}
