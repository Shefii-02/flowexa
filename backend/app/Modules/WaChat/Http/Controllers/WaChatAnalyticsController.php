<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/v1/wa-chat/analytics
 *
 * Company-scoped WA Chat analytics. The gateway's cross-session /stats/overview
 * and /stats/messages require an unrestricted ADMIN key, which a company never
 * has — so this aggregates the **per-session** stats (which a scoped company key
 * can read) over exactly the company's own sessions.
 */
class WaChatAnalyticsController extends Controller
{
    private function base(): string
    {
        return rtrim((string) config('services.open_wa.base_url'), '/');
    }

    public function index(): JsonResponse
    {
        $company = auth()->user()->company;
        $token   = (string) ($company?->wa_chat_token ?? '');

        $sessions = WahaSession::where('company_id', $company?->id)->get();

        if ($token === '' || $sessions->isEmpty()) {
            return response()->json([
                'connected'      => false,
                'overview'       => $this->emptyOverview($sessions->count()),
                'by_session'     => [],
                'hourly_activity'=> $this->emptyHours(),
                'top_chats'      => [],
            ]);
        }

        $bySession = [];
        $totals    = ['sent' => 0, 'received' => 0, 'today' => 0, 'failed' => 0];
        $hours     = array_fill(0, 24, ['hour' => 0, 'sent' => 0, 'received' => 0]);
        foreach ($hours as $h => $_) {
            $hours[$h]['hour'] = $h;
        }
        $chats = [];
        $reachable = false;

        foreach ($sessions as $s) {
            try {
                $res = Http::withHeaders(['X-API-Key' => $token])
                    ->timeout(8)->connectTimeout(4)
                    ->get("{$this->base()}/stats/sessions/{$s->session_name}");
            } catch (\Throwable) {
                $res = null;
            }

            if (! $res || ! $res->successful()) {
                $bySession[] = [
                    'id'       => $s->session_name,
                    'name'     => $s->display_name ?: $s->session_name,
                    'status'   => $s->status ?? 'unknown',
                    'sent'     => 0, 'received' => 0, 'today' => 0, 'failed' => 0,
                    'reachable'=> false,
                ];
                continue;
            }

            $reachable = true;
            $m = (array) $res->json('messages', []);
            $sent = (int) ($m['sent'] ?? 0);
            $recv = (int) ($m['received'] ?? 0);

            $totals['sent']     += $sent;
            $totals['received'] += $recv;
            $totals['today']    += (int) ($m['today'] ?? 0);
            $totals['failed']   += (int) ($m['failed'] ?? 0);

            foreach ((array) $res->json('hourlyActivity', []) as $row) {
                $h = (int) ($row['hour'] ?? -1);
                if ($h >= 0 && $h <= 23) {
                    $hours[$h]['sent']     += (int) ($row['sent'] ?? 0);
                    $hours[$h]['received'] += (int) ($row['received'] ?? 0);
                }
            }

            foreach ((array) $res->json('topChats', []) as $c) {
                $id = (string) ($c['chatId'] ?? '');
                if ($id === '') {
                    continue;
                }
                if (! isset($chats[$id])) {
                    $chats[$id] = ['chat_id' => $id, 'chat_name' => $c['chatName'] ?? null, 'count' => 0, 'last_active' => $c['lastActive'] ?? null];
                }
                $chats[$id]['count'] += (int) ($c['count'] ?? 0);
                if (($c['lastActive'] ?? '') > ($chats[$id]['last_active'] ?? '')) {
                    $chats[$id]['last_active'] = $c['lastActive'];
                }
            }

            $sess = (array) $res->json('session', []);
            $bySession[] = [
                'id'       => $s->session_name,
                'name'     => $sess['name'] ?? ($s->display_name ?: $s->session_name),
                'status'   => $sess['status'] ?? ($s->status ?? 'unknown'),
                'sent'     => $sent,
                'received' => $recv,
                'today'    => (int) ($m['today'] ?? 0),
                'failed'   => (int) ($m['failed'] ?? 0),
                'reachable'=> true,
            ];
        }

        $connected = collect($bySession)
            ->whereIn('status', ['ready', 'connected', 'working', 'authenticated'])
            ->count();

        $topChats = collect($chats)->sortByDesc('count')->take(10)->values()->all();

        return response()->json([
            'connected' => $reachable,
            'overview'  => [
                'sessions_total'     => $sessions->count(),
                'sessions_connected' => $connected,
                'messages_sent'      => $totals['sent'],
                'messages_received'  => $totals['received'],
                'messages_today'     => $totals['today'],
                'messages_failed'    => $totals['failed'],
            ],
            'by_session'      => $bySession,
            'hourly_activity' => array_values($hours),
            'top_chats'       => $topChats,
        ]);
    }

    private function emptyOverview(int $sessionCount): array
    {
        return [
            'sessions_total' => $sessionCount, 'sessions_connected' => 0,
            'messages_sent' => 0, 'messages_received' => 0, 'messages_today' => 0, 'messages_failed' => 0,
        ];
    }

    private function emptyHours(): array
    {
        return array_map(fn ($h) => ['hour' => $h, 'sent' => 0, 'received' => 0], range(0, 23));
    }
}
