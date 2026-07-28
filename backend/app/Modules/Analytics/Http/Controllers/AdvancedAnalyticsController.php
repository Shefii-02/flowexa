<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AdvancedAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedAnalyticsController extends Controller
{
    public function __construct(private readonly AdvancedAnalyticsService $advancedAnalyticsService) {}

    public function cohort(Request $request): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->cohortAnalysis(
                auth()->user()->company_id,
                (int) ($request->query('months') ?? 6)
            )
        );
    }

    public function staffCompare(): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->staffPerformanceComparison(auth()->user()->company_id)
        );
    }

    public function sendTime(): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->messageSendTimeAnalysis(auth()->user()->company_id)
        );
    }

    public function flowNodes(): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->flowNodePerformance(auth()->user()->company_id)
        );
    }

    public function campaignTrends(): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->campaignTrends(auth()->user()->company_id)
        );
    }

    public function burnRate(): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->walletBurnRate(auth()->user()->company_id)
        );
    }

    public function topLeads(Request $request): JsonResponse
    {
        return response()->json(
            $this->advancedAnalyticsService->topScoredLeads(
                auth()->user()->company_id,
                (int) ($request->query('limit') ?? 20)
            )
        );
    }
}
