<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\AutomationEngine;
use App\Modules\WaChat\Services\Rag\RagOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function update(Request $request, int $id): JsonResponse
    {
        $session = WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'display_name' => 'nullable|string|max:150',
            'phone'        => 'nullable|string|max:30',
            'webhook_url'  => 'nullable|url|max:500',
        ]);
        $session->update(array_filter($data, fn($v) => !is_null($v)));
        return response()->json(['message' => 'Session updated.', 'data' => $session]);
    }

    public function groups(Request $request): JsonResponse
    {
        $sessionId = $request->query('session_id', $request->query('session', 'default'));
        $wahaBase  = $this->wahaBase();
        $wahaKey   = $this->wahaHeaders()['X-API-Key'];

        try {
            $res = Http::withHeaders(['X-API-Key' => $wahaKey])
                ->timeout(15)
                ->get("{$wahaBase}/api/groups", ['session' => $sessionId]);

            if ($res->successful()) {
                return response()->json(['data' => $res->json()]);
            }
            return response()->json(['data' => [], 'error' => 'WAHA returned ' . $res->status()], 200);
        } catch (\Exception $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()], 200);
        }
    }

    public function groupParticipants(Request $request): JsonResponse
    {
        $sid = $request->query('session_id', $request->query('session', 'default'));
        $gid = rawurlencode($request->query('group_id', ''));

        try {
            // WAHA has no GET .../participants endpoint; participants live in group detail
            $res = Http::withHeaders($this->wahaHeaders())
                ->timeout(15)
                ->get("{$this->wahaBase()}/api/sessions/{$sid}/groups/{$gid}");

            if ($res->successful()) {
                $data = $res->json();
                return response()->json(['participants' => $data['participants'] ?? []]);
            }
            return response()->json(['participants' => []], 200);
        } catch (\Exception $e) {
            return response()->json(['participants' => []], 200);
        }
    }

    // ── Group helper ──────────────────────────────────────────────────────────
    // WAHA uses /api/groups/{gid}/action?session={sid} (session as query param)
    private function wahaGroup(string $method, string $path, array $query = [], array $body = []): JsonResponse
    {
        try {
            $req = Http::withHeaders($this->wahaHeaders())->timeout(15);
            $url = $this->wahaBase() . $path;

            $res = match ($method) {
                'GET'    => $req->get($url, $query),
                'POST'   => empty($query) ? $req->post($url, $body) : $req->withQueryParameters($query)->post($url, $body),
                'PUT'    => empty($query) ? $req->put($url, $body)  : $req->withQueryParameters($query)->put($url, $body),
                'DELETE' => empty($query) ? $req->delete($url, $body) : $req->withQueryParameters($query)->delete($url, $body),
                default  => throw new \InvalidArgumentException("Unsupported method $method"),
            };

            return $res->successful()
                ? response()->json($res->json())
                : response()->json(['error' => 'WAHA error ' . $res->status(), 'body' => $res->json()], $res->status());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function groupInfo(Request $request): JsonResponse
    {
        $sid = $request->query('session_id', 'default');
        $gid = $request->query('group_id', '');

        try {
            $res = Http::withHeaders($this->wahaHeaders())
                ->timeout(15)
                ->get("{$this->wahaBase()}/api/groups", ['session' => $sid]);

            if ($res->successful()) {
                $groups = $res->json();
                $group  = collect(is_array($groups) ? $groups : ($groups['data'] ?? []))
                    ->firstWhere('id', $gid);
                return response()->json($group ?? []);
            }
            return response()->json(['error' => 'WAHA error ' . $res->status()], $res->status());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function createGroup(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        return $this->wahaGroup('POST', '/api/groups', ['session' => $sid], $request->only(['name', 'participants']));
    }

    public function addParticipants(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/participants", ['session' => $sid], ['participants' => $request->input('participants', [])]);
    }

    public function removeParticipants(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('DELETE', "/api/groups/{$gid}/participants", ['session' => $sid], ['participants' => $request->input('participants', [])]);
    }

    public function promoteParticipants(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/participants/promote", ['session' => $sid], ['participants' => $request->input('participants', [])]);
    }

    public function demoteParticipants(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/participants/demote", ['session' => $sid], ['participants' => $request->input('participants', [])]);
    }

    public function updateGroupSubject(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('PUT', "/api/groups/{$gid}/subject", ['session' => $sid], ['subject' => $request->input('subject', '')]);
    }

    public function updateGroupDescription(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('PUT', "/api/groups/{$gid}/description", ['session' => $sid], ['description' => $request->input('description', '')]);
    }

    public function getGroupInviteCode(Request $request): JsonResponse
    {
        $sid = $request->query('session_id', 'default');
        $gid = rawurlencode($request->query('group_id', ''));
        return $this->wahaGroup('GET', "/api/groups/{$gid}/invite-code", ['session' => $sid]);
    }

    public function revokeGroupInviteCode(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/invite-code/revoke", ['session' => $sid]);
    }

    public function leaveGroup(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/leave", ['session' => $sid]);
    }

    public function getMembershipRequests(Request $request): JsonResponse
    {
        $sid = $request->query('session_id', 'default');
        $gid = rawurlencode($request->query('group_id', ''));
        return $this->wahaGroup('GET', "/api/groups/{$gid}/membership-requests", ['session' => $sid]);
    }

    public function approveMembershipRequests(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/membership-requests/approve", ['session' => $sid], ['participants' => $request->input('participants', [])]);
    }

    public function rejectMembershipRequests(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('POST', "/api/groups/{$gid}/membership-requests/reject", ['session' => $sid], ['participants' => $request->input('participants', [])]);
    }

    public function joinGroup(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        return $this->wahaGroup('POST', '/api/groups/join', ['session' => $sid], ['code' => $request->input('code', '')]);
    }

    public function getJoinInfo(Request $request): JsonResponse
    {
        $sid  = $request->query('session_id', 'default');
        $code = $request->query('code', '');
        return $this->wahaGroup('GET', '/api/groups/join-info', ['session' => $sid, 'code' => $code]);
    }

    public function getGroupPicture(Request $request): JsonResponse
    {
        $sid = $request->query('session_id', 'default');
        $gid = rawurlencode($request->query('group_id', ''));
        return $this->wahaGroup('GET', "/api/groups/{$gid}/picture", ['session' => $sid]);
    }

    public function deleteGroupPicture(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        return $this->wahaGroup('DELETE', "/api/groups/{$gid}/picture", ['session' => $sid]);
    }

    public function getGroupSettings(Request $request): JsonResponse
    {
        $sid = $request->query('session_id', 'default');
        $gid = rawurlencode($request->query('group_id', ''));
        return $this->wahaGroup('GET', "/api/groups/{$gid}/settings", ['session' => $sid]);
    }

    public function updateGroupSettings(Request $request): JsonResponse
    {
        $sid = $request->input('session_id', 'default');
        $gid = rawurlencode($request->input('group_id', ''));
        $body = $request->only(['messagesAdminsOnly', 'infoAdminsOnly', 'joinApprovalMode']);
        return $this->wahaGroup('PUT', "/api/groups/{$gid}/settings", ['session' => $sid], $body);
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

        // Run automation rules on inbound messages
        if ($session && in_array($event, ['message', 'message.any', 'messages.upsert'])) {
            $fromMe = $payload['fromMe'] ?? false;
            if (!$fromMe) {
                try {
                    $eventData = [
                        'session' => $name,
                        'from'    => $payload['from'] ?? ($payload['chatId'] ?? null),
                        'body'    => $payload['body'] ?? ($payload['text'] ?? ''),
                        'type'    => $payload['type'] ?? 'text',
                    ];
                    app(AutomationEngine::class)->handleIncomingMessage($eventData);
                } catch (\Exception $e) {
                    Log::error('Webhook AutomationEngine error: ' . $e->getMessage());
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
