<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WahaSessionController extends Controller
{
    private function wahaBase(): string
    {
        return rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
    }

    private function wahaHeaders(): array
    {
        return ['X-API-Key' => config('services.waha.api_key', env('WAHA_API_KEY', ''))];
    }

    public function index(): JsonResponse
    {
        $sessions = WahaSession::where('company_id', auth()->user()->company_id)
            ->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $sessions]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $company   = auth()->user()->company;

        $count = WahaSession::where('company_id', $companyId)->count();
        if ($count >= ($company->waha_max_sessions ?? 1)) {
            return response()->json(['message' => 'Session limit reached. Upgrade your plan to add more sessions.'], 422);
        }

        $data = $request->validate([
            'session_name'  => 'required|string|max:100|unique:waha_sessions,session_name',
            'display_name'  => 'nullable|string|max:150',
            'engine'        => 'nullable|string|max:30',
            'webhook_url'   => 'nullable|url|max:500',
        ]);

        $session = WahaSession::create(array_merge($data, ['company_id' => $companyId]));

        // Create session in WAHA
        Http::withHeaders($this->wahaHeaders())
            ->post("{$this->wahaBase()}/api/sessions", [
                'name'   => $session->session_name,
                'config' => ['webhooks' => $session->webhook_url ? [['url' => $session->webhook_url, 'events' => ['message', 'session.status']]] : []],
            ]);

        return response()->json(['message' => 'Session created.', 'data' => $session], 201);
    }

    public function show(int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        return response()->json(['data' => $session]);
    }

    public function start(int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $session->update(['status' => 'starting']);
        Http::withHeaders($this->wahaHeaders())->post("{$this->wahaBase()}/api/sessions/{$session->session_name}/start");
        return response()->json(['message' => 'Session starting.']);
    }

    public function stop(int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $session->update(['status' => 'stopped']);
        Http::withHeaders($this->wahaHeaders())->post("{$this->wahaBase()}/api/sessions/{$session->session_name}/stop");
        return response()->json(['message' => 'Session stopped.']);
    }

    public function logout(int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $session->update(['status' => 'stopped', 'phone' => null]);
        Http::withHeaders($this->wahaHeaders())->post("{$this->wahaBase()}/api/sessions/{$session->session_name}/logout");
        return response()->json(['message' => 'Session logged out.']);
    }

    public function qr(int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $res = Http::withHeaders($this->wahaHeaders())
            ->get("{$this->wahaBase()}/api/sessions/{$session->session_name}/auth/qr");
        return response()->json($res->json());
    }

    public function destroy(int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        Http::withHeaders($this->wahaHeaders())->delete("{$this->wahaBase()}/api/sessions/{$session->session_name}");
        $session->delete();
        return response()->json(['message' => 'Session deleted.']);
    }

    // WAHA sends events here — update session status in DB
    public function webhook(Request $request): JsonResponse
    {
        $event   = $request->input('event');
        $name    = $request->input('session');
        $payload = $request->input('payload', []);

        $session = WahaSession::where('session_name', $name)->first();
        if ($session && $event === 'session.status') {
            $status = $payload['status'] ?? 'disconnected';
            $phone  = $payload['phone'] ?? $session->phone;
            $session->update(['status' => $status, 'phone' => $phone, 'last_seen_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
