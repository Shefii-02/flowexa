<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WahaWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WahaWebhookConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $webhooks = WahaWebhook::where('company_id', auth()->user()->company_id)
            ->with('session:id,session_name,display_name')
            ->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $webhooks]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $company   = auth()->user()->company;

        $count = WahaWebhook::where('company_id', $companyId)->count();
        if ($count >= ($company->waha_max_webhooks ?? 3)) {
            return response()->json(['message' => 'Webhook limit reached.'], 422);
        }

        $data = $request->validate([
            'name'       => 'required|string|max:150',
            'url'        => 'required|url|max:500',
            'events'     => 'nullable|array',
            'secret'     => 'nullable|string|max:255',
            'session_id' => 'nullable|integer|exists:waha_sessions,id',
            'is_active'  => 'boolean',
        ]);

        $webhook = WahaWebhook::create(array_merge($data, ['company_id' => $companyId]));
        return response()->json(['message' => 'Webhook created.', 'data' => $webhook], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = WahaWebhook::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'name'      => 'sometimes|string|max:150',
            'url'       => 'sometimes|url|max:500',
            'events'    => 'nullable|array',
            'secret'    => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);
        $webhook->update($data);
        return response()->json(['message' => 'Webhook updated.', 'data' => $webhook]);
    }

    public function destroy(int $id): JsonResponse
    {
        WahaWebhook::where('company_id', auth()->user()->company_id)->findOrFail($id)->delete();
        return response()->json(['message' => 'Webhook deleted.']);
    }

    public function test(int $id): JsonResponse
    {
        $webhook = WahaWebhook::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $start   = microtime(true);
        try {
            $res = Http::timeout(10)->post($webhook->url, [
                'event'   => 'test.ping',
                'source'  => 'waapi',
                'message' => 'Test webhook from WA Chat',
            ]);
            $ms = (int)((microtime(true) - $start) * 1000);
            $webhook->update(['last_triggered_at' => now(), 'last_status_code' => $res->status()]);
            return response()->json(['success' => true, 'status_code' => $res->status(), 'response_ms' => $ms]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
