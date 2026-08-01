<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Plan;
use App\Modules\Settings\DTOs\MessageLogFilterDTO;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use App\Modules\Settings\Http\Requests\SuperAdminCreateCompanyRequest;
use App\Modules\Settings\Http\Requests\TopUpRequest;
use App\Modules\Settings\Http\Requests\UpdateCompanyStatusRequest;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Http\Requests\WaCredentialsRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SuperAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


// ─── Message Log Controller ───────────────────────────────────────────────────
class MessageLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = MessageLogFilterDTO::fromRequest($request->all());

        $logs = MessageLog::where('company_id', auth()->user()->company_id)
            ->when($filter->direction, fn($q) => $q->where('direction', $filter->direction))
            ->when($filter->type,      fn($q) => $q->where('type',      $filter->type))
            ->when($filter->status,    fn($q) => $q->where('status',    $filter->status))
            ->when($filter->phone,     fn($q) => $q->where('phone', 'like', "%{$filter->phone}%"))
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);

        return response()->json($logs);

        //     $logs = MessageLog::with('contact')
//         ->where('company_id', auth()->user()->company_id)
//         ->when($request->direction, fn($q) => $q->where('direction', $request->direction))
//         ->when($request->contact_id, fn($q) => $q->where('contact_id', $request->contact_id))
//         ->when($request->from, fn($q) => $q->whereDate('created_at', '>=', $request->from))
//         ->when($request->to,   fn($q) => $q->whereDate('created_at', '<=', $request->to))
//         ->latest()
//         ->paginate(50);
//     return response()->json($logs);

    }

    public function show(MessageLog $log): JsonResponse
    {
        abort_if($log->company_id !== auth()->user()->company_id, 403);
        return response()->json(['log' => $log]);
    }
}
