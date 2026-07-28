<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    // GET /v1/analytics/overview
    public function overview(): JsonResponse
    {
        return response()->json(
            $this->analyticsService->overview(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/campaigns
    public function campaigns(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->campaigns(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/flows
    public function flows(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->flows(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/staff
    public function staff(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->staff(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/wallet
    public function wallet(): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('billing.view'), 403, 'No permission.');
        return response()->json(
            $this->analyticsService->wallet(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/leads
    public function leads(): JsonResponse
    {
        return response()->json(
            $this->analyticsService->leads(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/messages
    public function messages(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->messages(auth()->user()->company_id)
        );
    }

    private function authorizeViewAll(): void
    {
        abort_unless(
            auth()->user()->hasAnyPermission(['analytics.view_all', 'analytics.view_own']),
            403,
            'No analytics permission.'
        );
    }
}
