<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WaCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WA Cloud → Inbox Analytics. Message + call activity for a company's inbox,
 * with date / direction / status / agent / label filters.
 */
class WaCloudInboxAnalyticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $companyId = (int) auth()->user()->company_id;

        $data = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'direction' => 'nullable|in:inbound,outbound',
            'status'    => 'nullable|string|max:30',
            'agent_id'  => 'nullable|integer',
            'label_id'  => 'nullable|integer',
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to   = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        // Contact-id scope for the agent / label filters.
        $contactIds = null;
        if (!empty($data['label_id'])) {
            $contactIds = DB::table('contact_label_pivot')
                ->where('contact_label_id', $data['label_id'])
                ->pluck('contact_id')->all();
        }
        if (!empty($data['agent_id'])) {
            $agentContacts = DB::table('wa_conversations')
                ->where('company_id', $companyId)
                ->where('assigned_to', $data['agent_id'])
                ->pluck('contact_id')->all();
            $contactIds = $contactIds === null
                ? $agentContacts
                : array_values(array_intersect($contactIds, $agentContacts));
        }

        return response()->json([
            'range'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'messages' => $this->messageStats($companyId, $from, $to, $data, $contactIds),
            'calls'    => $this->callStats($companyId, $from, $to, $data, $contactIds),
            'inbox'    => $this->inboxStats($companyId, $from, $to, $data),
        ]);
    }

    /** Agents that have handled a conversation — for the filter dropdown. */
    public function agents(): JsonResponse
    {
        $companyId = (int) auth()->user()->company_id;

        $rows = DB::table('wa_conversations')
            ->join('users', 'users.id', '=', 'wa_conversations.assigned_to')
            ->where('wa_conversations.company_id', $companyId)
            ->whereNotNull('wa_conversations.assigned_to')
            ->distinct()
            ->get(['users.id', 'users.name']);

        return response()->json(['data' => $rows]);
    }

    /** @param array<string,mixed> $f  @param list<int>|null $contactIds */
    private function messageStats(int $companyId, Carbon $from, Carbon $to, array $f, ?array $contactIds): array
    {
        $base = fn () => DB::table('message_logs')
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->when(!empty($f['direction']), fn ($q) => $q->where('direction', $f['direction']))
            ->when(!empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when($contactIds !== null, fn ($q) => $q->whereIn('contact_id', $contactIds ?: [0]));

        $daily = $base()
            ->selectRaw('DATE(created_at) as date, direction, COUNT(*) as total')
            ->groupBy('date', 'direction')->orderBy('date')->get()
            ->groupBy('date')
            ->map(fn ($g) => [
                'inbound'  => (int) $g->where('direction', 'inbound')->sum('total'),
                'outbound' => (int) $g->where('direction', 'outbound')->sum('total'),
            ]);

        return [
            'total_inbound'  => (int) (clone $base())->where('direction', 'inbound')->count(),
            'total_outbound' => (int) (clone $base())->where('direction', 'outbound')->count(),
            'by_status'      => $base()->whereNotNull('status')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'by_type'        => $base()->whereNotNull('type')->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type'),
            'daily'          => $daily,
        ];
    }

    /** @param array<string,mixed> $f  @param list<int>|null $contactIds */
    private function callStats(int $companyId, Carbon $from, Carbon $to, array $f, ?array $contactIds): array
    {
        $base = fn () => WaCall::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->when(!empty($f['direction']), fn ($q) => $q->where('direction', $f['direction']))
            ->when(!empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(!empty($f['agent_id']), fn ($q) => $q->where('assigned_to', $f['agent_id']))
            ->when($contactIds !== null && empty($f['agent_id']), fn ($q) => $q->whereIn('contact_id', $contactIds ?: [0]));

        $daily = $base()
            ->selectRaw('DATE(created_at) as date, direction, COUNT(*) as total')
            ->groupBy('date', 'direction')->orderBy('date')->get()
            ->groupBy('date')
            ->map(fn ($g) => [
                'inbound'  => (int) $g->where('direction', 'inbound')->sum('total'),
                'outbound' => (int) $g->where('direction', 'outbound')->sum('total'),
            ]);

        $connected = (clone $base())->where('status', 'completed');

        return [
            'total'            => (int) (clone $base())->count(),
            'total_inbound'    => (int) (clone $base())->where('direction', 'inbound')->count(),
            'total_outbound'   => (int) (clone $base())->where('direction', 'outbound')->count(),
            'missed'           => (int) (clone $base())->whereIn('status', WaCall::UNANSWERED)->count(),
            'connected'        => (int) $connected->count(),
            'total_duration_s' => (int) (clone $base())->sum('duration_seconds'),
            'avg_duration_s'   => (int) round((clone $base())->whereNotNull('duration_seconds')->avg('duration_seconds') ?? 0),
            'by_status'        => $base()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'daily'            => $daily,
        ];
    }

    /** @param array<string,mixed> $f */
    private function inboxStats(int $companyId, Carbon $from, Carbon $to, array $f): array
    {
        $base = fn () => DB::table('wa_conversations')
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->when(!empty($f['agent_id']), fn ($q) => $q->where('assigned_to', $f['agent_id']));

        return [
            'total_conversations' => (int) (clone $base())->count(),
            'unassigned'          => (int) (clone $base())->whereNull('assigned_to')->count(),
            'open'                => (int) (clone $base())->where('status', 'open')->count(),
            'by_agent'            => DB::table('wa_conversations')
                ->leftJoin('users', 'users.id', '=', 'wa_conversations.assigned_to')
                ->where('wa_conversations.company_id', $companyId)
                ->whereBetween('wa_conversations.created_at', [$from, $to])
                ->when(!empty($f['agent_id']), fn ($q) => $q->where('wa_conversations.assigned_to', $f['agent_id']))
                ->selectRaw("COALESCE(users.name, 'Unassigned') as agent, COUNT(*) as total")
                ->groupBy('agent')->orderByDesc('total')->get(),
        ];
    }
}
