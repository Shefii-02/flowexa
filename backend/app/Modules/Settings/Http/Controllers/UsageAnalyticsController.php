<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Settings\Services\UsageAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Superadmin → Usage analytics (portal vs app, per company, per module). */
class UsageAnalyticsController extends Controller
{
    public function __construct(private readonly UsageAnalytics $analytics) {}

    /** GET superadmin/usage-analytics?days= */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:30']]);
        return response()->json($this->analytics->overview((int) ($d['days'] ?? 7)));
    }

    /** GET superadmin/usage-analytics/{company}?days= */
    public function company(Request $request, int $company): JsonResponse
    {
        $d = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:30']]);
        Company::findOrFail($company);
        return response()->json($this->analytics->company($company, (int) ($d['days'] ?? 7)));
    }
}
