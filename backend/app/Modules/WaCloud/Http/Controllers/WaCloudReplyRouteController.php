<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FlowBuilder;
use App\Models\User;
use App\Models\WaPhoneNumber;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaCloud\Models\WaCloudAutomationLog;
use App\Modules\WaCloud\Models\WaCloudReplyRoute;
use App\Modules\WaCloud\Models\WaCloudRouteEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reply routing (incoming message → chatbot flow / AI agent / sales-team handover / auto-reply / nobody, per number or
 * all numbers) + the analytics behind it. See {@see \App\Modules\WaCloud\Services\WaCloudReplyRouter}.
 */
class WaCloudReplyRouteController extends Controller
{
    private function cid(): int { return (int) auth()->user()->company_id; }

    private function find(int $id): WaCloudReplyRoute { return WaCloudReplyRoute::where('company_id', $this->cid())->findOrFail($id); }

    public function index(): JsonResponse
    {
        return response()->json(WaCloudReplyRoute::where('company_id', $this->cid())
            ->orderByRaw('wa_phone_number_id IS NULL')->orderByDesc('priority')->orderBy('id')->get());
    }

    /** Everything the form's pickers need, in one call. */
    public function options(): JsonResponse
    {
        $c = $this->cid();
        return response()->json([
            'phone_numbers' => WaPhoneNumber::where('company_id', $c)->orderBy('id')->get(['id', 'label', 'display_number']),
            'flows'         => FlowBuilder::where('company_id', $c)->orderBy('name')->get(['id', 'name', 'is_active', 'trigger_type']),
            'agents'        => AgentPlaybook::where('company_id', $c)->orderBy('id')->get(['id', 'agent_name', 'session_id', 'is_active']),
            'staff'         => User::where('company_id', $c)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $r): JsonResponse
    {
        $data = $this->validated($r);
        $data['company_id'] = $this->cid();
        return response()->json(WaCloudReplyRoute::create($data), 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $route = $this->find($id);
        $route->update($this->validated($r, true));
        return response()->json($route);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function toggle(int $id): JsonResponse
    {
        $route = $this->find($id);
        $route->update(['is_active' => !$route->is_active]);
        return response()->json($route);
    }

    /** @return array<string,mixed> */
    private function validated(Request $r, bool $update = false): array
    {
        $c = $this->cid();
        $req = $update ? 'sometimes' : 'required';
        $d = $r->validate([
            'wa_phone_number_id' => ['nullable', 'integer', Rule::exists('wa_phone_numbers', 'id')->where('company_id', $c)],
            'name'            => [$req, 'string', 'max:120'],
            'when_mode'       => ['nullable', Rule::in(WaCloudReplyRoute::WHEN)],
            'schedule_days'   => ['nullable', 'array'],
            'schedule_start'  => ['nullable', 'date_format:H:i'],
            'schedule_end'    => ['nullable', 'date_format:H:i'],
            'response_type'   => [$req, Rule::in(WaCloudReplyRoute::RESPONSE_TYPES)],
            'flow_builder_id' => ['nullable', 'integer', Rule::exists('flow_builders', 'id')->where('company_id', $c)],
            'playbook_id'     => ['nullable', 'integer', Rule::exists('agent_playbooks', 'id')->where('company_id', $c)],
            'handover'        => ['nullable', 'array'],
            'handover.user_ids'   => ['nullable', 'array'],
            'handover.user_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'handover.strategy'   => ['nullable', Rule::in(['round_robin', 'least_busy'])],
            'handover.ack_message' => ['nullable', 'string', 'max:1000'],
            'reply_text'      => ['nullable', 'string', 'max:4096'],
            'fallback_type'   => ['nullable', Rule::in(WaCloudReplyRoute::FALLBACKS)],
            'priority'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active'       => ['boolean'],
        ]);
        $type = $d['response_type'] ?? null;
        abort_if($type === 'chatbot' && array_key_exists('flow_builder_id', $d) && !$d['flow_builder_id'] && false, 422);
        abort_if(in_array($type, ['auto_reply'], true) && !filled($d['reply_text'] ?? null), 422, 'Write the auto-reply message.');
        abort_if($type === 'ai_agent' && empty($d['playbook_id']), 422, 'Choose which AI agent should answer.');
        abort_if(($type === 'handover' || ($d['fallback_type'] ?? '') === 'handover') && empty($d['handover']['user_ids'] ?? []), 422, 'Choose the sales-team users to hand over to.');
        return $d;
    }

    // ── Analytics ────────────────────────────────────────────────────────────

    /** GET /wa-cloud/reply-routes/analytics?days=30&wa_phone_number_id= */
    public function analytics(Request $r): JsonResponse
    {
        $c = $this->cid();
        $days = min(max((int) $r->query('days', 30), 1), 365);
        $from = now()->subDays($days - 1)->startOfDay();
        $num = $r->filled('wa_phone_number_id') ? (int) $r->query('wa_phone_number_id') : null;

        $ev = fn () => WaCloudRouteEvent::where('company_id', $c)->where('created_at', '>=', $from)->when($num, fn ($q) => $q->where('wa_phone_number_id', $num));
        $total = $ev()->count();
        $byOutcome = $ev()->select('outcome', DB::raw('count(*) as n'))->groupBy('outcome')->pluck('n', 'outcome');
        $byFinal = $ev()->select(DB::raw("COALESCE(final_type, response_type) as t"), DB::raw('count(*) as n'))->groupBy('t')->pluck('n', 't');

        $labels = WaPhoneNumber::where('company_id', $c)->pluck('label', 'id');
        $byNumber = $ev()->select('wa_phone_number_id', DB::raw("COALESCE(final_type, response_type) as t"), DB::raw('count(*) as n'))
            ->groupBy('wa_phone_number_id', 't')->get()->groupBy('wa_phone_number_id')->map(fn ($g, $id) => [
                'wa_phone_number_id' => $id ?: null, 'label' => $id ? ($labels[$id] ?? "#{$id}") : 'Unknown number',
                'total' => $g->sum('n'), 'by_type' => $g->pluck('n', 't'),
            ])->values();

        $daily = $ev()->select(DB::raw('DATE(created_at) as d'), DB::raw("COALESCE(final_type, response_type) as t"), DB::raw('count(*) as n'))
            ->groupBy('d', 't')->orderBy('d')->get()->groupBy('d')->map(fn ($g, $d) => ['date' => $d] + $g->pluck('n', 't')->all())->values();

        $staffRows = $ev()->whereNotNull('assigned_to')->select('assigned_to', DB::raw('count(*) as n'))->groupBy('assigned_to')->orderByDesc('n')->limit(15)->get();
        $names = User::whereIn('id', $staffRows->pluck('assigned_to'))->pluck('name', 'id');
        $handovers = $staffRows->map(fn ($x) => ['user_id' => $x->assigned_to, 'name' => $names[$x->assigned_to] ?? "#{$x->assigned_to}", 'count' => (int) $x->n])->values();

        $topRoutes = $ev()->whereNotNull('route_id')->select('route_id', DB::raw('count(*) as n'))->groupBy('route_id')->orderByDesc('n')->limit(10)->get();
        $routeNames = WaCloudReplyRoute::where('company_id', $c)->whereIn('id', $topRoutes->pluck('route_id'))->pluck('name', 'id');

        // Automation rules (welcome / keyword / out-of-office / follow-up …)
        $lg = fn () => WaCloudAutomationLog::where('company_id', $c)->where('created_at', '>=', $from)->when($num, fn ($q) => $q->where('wa_phone_number_id', $num));
        $rulesByType = $lg()->select('rule_type', 'status', DB::raw('count(*) as n'))->groupBy('rule_type', 'status')->get()
            ->groupBy('rule_type')->map(fn ($g, $t) => ['rule_type' => $t, 'success' => (int) $g->where('status', 'success')->sum('n'), 'failed' => (int) $g->where('status', 'failed')->sum('n')])->values();
        $rulesDaily = $lg()->select(DB::raw('DATE(created_at) as d'), 'status', DB::raw('count(*) as n'))->groupBy('d', 'status')->orderBy('d')->get()
            ->groupBy('d')->map(fn ($g, $d) => ['date' => $d, 'success' => (int) $g->where('status', 'success')->sum('n'), 'failed' => (int) $g->where('status', 'failed')->sum('n')])->values();
        $topRules = $lg()->select('rule_id', DB::raw('count(*) as n'))->groupBy('rule_id')->orderByDesc('n')->limit(8)->get();
        $ruleNames = \App\Modules\WaCloud\Models\WaCloudAutomationRule::where('company_id', $c)->whereIn('id', $topRules->pluck('rule_id'))->pluck('name', 'id');

        $handled = (int) ($byOutcome['handled'] ?? 0);
        return response()->json([
            'days' => $days,
            'totals' => ['events' => $total, 'handled' => $handled, 'fallback' => (int) ($byOutcome['fallback'] ?? 0),
                         'skipped' => (int) ($byOutcome['skipped'] ?? 0), 'failed' => (int) ($byOutcome['failed'] ?? 0),
                         'handled_rate' => $total ? round(($handled + (int) ($byOutcome['fallback'] ?? 0)) / $total * 100, 1) : 0],
            'by_type' => $byFinal, 'by_number' => $byNumber, 'daily' => $daily, 'handovers' => $handovers,
            'top_routes' => $topRoutes->map(fn ($x) => ['route_id' => $x->route_id, 'name' => $routeNames[$x->route_id] ?? "#{$x->route_id}", 'count' => (int) $x->n])->values(),
            'rules' => ['by_type' => $rulesByType, 'daily' => $rulesDaily,
                        'top' => $topRules->map(fn ($x) => ['rule_id' => $x->rule_id, 'name' => $ruleNames[$x->rule_id] ?? "#{$x->rule_id}", 'count' => (int) $x->n])->values()],
        ]);
    }
}
