<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Models\WaAuthMessage;
use App\Modules\WaChat\Models\WaOtpLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WaOtpServiceController extends Controller
{
    public function show(): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->first();
        return response()->json(['data' => $service]);
    }

    public function storeOrUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active'            => 'boolean',
            'allowed_domains'      => 'nullable|array',
            'allowed_packages'     => 'nullable|array',
            'otp_expiry_minutes'   => 'integer|min:1|max:60',
            'otp_length'           => 'integer|min:4|max:8',
            'otp_message_template' => 'nullable|string|max:500',
            'session_id'           => 'nullable|string|max:100',
        ]);

        $companyId = auth()->user()->company_id;
        $service = WaOtpService::updateOrCreate(['company_id' => $companyId], $data);
        return response()->json(['message' => 'OTP service saved.', 'data' => $service]);
    }

    public function resetToken(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $service = WaOtpService::firstOrCreate(['company_id' => $companyId]);
        $service->update([
            'api_token'            => Str::random(64),
            'api_token_created_at' => now(),
        ]);
        return response()->json(['message' => 'Token regenerated.', 'data' => $service]);
    }

    public function stopToken(): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->firstOrFail();
        $service->update(['is_active' => false, 'api_token' => null]);
        return response()->json(['message' => 'Token revoked and service deactivated.']);
    }

    public function listAuthMessages(): JsonResponse
    {
        $messages = WaAuthMessage::where('company_id', auth()->user()->company_id)
            ->orderBy('sort_order')->get();

        // Seed defaults if empty
        if ($messages->isEmpty()) {
            $defaults = WaAuthMessage::defaultTemplates();
            $rows = [];
            foreach ($defaults as $i => $d) {
                $rows[] = WaAuthMessage::create(array_merge($d, [
                    'company_id' => auth()->user()->company_id,
                    'sort_order' => $i,
                ]));
            }
            $messages = collect($rows);
        }

        return response()->json(['data' => $messages]);
    }

    public function createAuthMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:150',
            'type'             => 'required|in:otp,welcome,password_reset,login_alert,custom',
            'message_template' => 'required|string',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
        ]);
        $msg = WaAuthMessage::create(array_merge($data, ['company_id' => auth()->user()->company_id]));
        return response()->json(['message' => 'Auth message created.', 'data' => $msg], 201);
    }

    public function updateAuthMessage(Request $request, int $id): JsonResponse
    {
        $msg = WaAuthMessage::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'name'             => 'sometimes|string|max:150',
            'message_template' => 'sometimes|string',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
        ]);
        $msg->update($data);
        return response()->json(['message' => 'Auth message updated.', 'data' => $msg]);
    }

    public function logs(Request $request): JsonResponse
    {
        $service = WaOtpService::where('company_id', auth()->user()->company_id)->firstOrFail();
        $logs = WaOtpLog::where('service_id', $service->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);
        return response()->json($logs);
    }
}
