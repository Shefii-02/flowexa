<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Support\SenderLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Superadmin: how fast companies may send from the WA Chat message sender. */
class SenderLimitsAdminController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => SenderLimits::get(), 'defaults' => SenderLimits::DEFAULTS]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'default_per_min_min'  => 'required|integer|min:1',
            'default_per_min_max'  => 'required|integer|min:1',
            'default_per_5min_cap' => 'required|integer|min:1',
            'max_per_min'          => 'required|integer|min:1|max:600',
            'min_per_5min_cap'     => 'required|integer|min:1',
            'max_per_5min_cap'     => 'required|integer|min:1|max:3000',
            'min_share_numbers'    => 'required|integer|min:1|max:20',
            'max_variants'         => 'required|integer|min:1|max:30',
        ]);
        return response()->json(['data' => SenderLimits::save($d), 'message' => 'Sending limits saved.']);
    }
}
