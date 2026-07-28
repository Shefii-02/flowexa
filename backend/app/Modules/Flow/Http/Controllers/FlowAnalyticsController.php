<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Flow\Http\Resources\FlowAnalyticsResource;
use App\Modules\Flow\Services\FlowService;
use Illuminate\Http\JsonResponse;

class FlowAnalyticsController extends Controller
{
    public function __construct(
        private readonly FlowService $flowService,
    ) {}

    // ─── GET /flow/analytics ──────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $nodes = $this->flowService->analytics(auth()->user()->company_id);

        return response()->json(
            FlowAnalyticsResource::toArray($nodes)
        );
    }
}
