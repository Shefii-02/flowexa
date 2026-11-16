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
        // Older single-type routes are returned as the equivalent chain, so the UI only deals with steps.
        return response()->json(WaCloudReplyRoute::where('company_id', $this->cid())
            ->orderByRaw('wa_phone_number_id IS NULL')->orderByDesc('priority')->orderBy('id')->get()
            ->map(fn (WaCloudReplyRoute $r) => array_merge($r->toArray(), ['steps' => $r->resolvedSteps()])));
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
            // handover = the company's lead-routing rule; the UI shows it read-only and links to where it is edited
            'lead_rule'     => app(\App\Modules\WaCloud\Services\WaCloudReplyRouter::class)->ruleSummary(auth()->user()->company),
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

    /**
     * POST /wa-cloud/reply-routes/seed-samples — example chains for the common setups. Created PAUSED, for all numbers,
     * using the company's own first AI agent / default chatbot where they exist, and skipped when already present.
     */
    public function seedSamples(): JsonResponse
    {
        $c = $this->cid();
        $agent = AgentPlaybook::where('company_id', $c)->where('is_active', true)->orderBy('id')->value('id');
        $on = ['mode' => 'always'];
        $day = ['mode' => 'in_hours', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'start' => '09:00', 'end' => '18:00'];
        $night = ['mode' => 'out_of_hours', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'start' => '09:00', 'end' => '18:00'];
        $staff = fn (?array $sc = null) => ['type' => 'handover', 'handover' => ['user_ids' => [], 'ack_message' => 'Connecting you to our team…'], 'schedule' => $sc ?? $on];
        $ai = fn (?array $sc = null) => ['type' => 'ai_agent', 'playbook_id' => $agent, 'schedule' => $sc ?? $on];
        $bot = ['type' => 'chatbot', 'flow_builder_id' => null, 'schedule' => $on];
        $closed = ['type' => 'auto_reply', 'reply_text' => "We're closed right now 🌙 Our team will reply when we open at 9 AM.", 'schedule' => $night];

        $samples = [
            ['Sample – Chatbot → AI → Staff', $agent ? [$bot, $ai(), $staff()] : [$bot, $staff()], ['agent', 'human', 'person', 'representative']],
            ['Sample – AI → Staff', $agent ? [$ai(), $staff()] : [$staff()], ['agent', 'human', 'person']],
            ['Sample – Staff only', [$staff()], []],
            ['Sample – Staff by day, AI at night', $agent ? [$staff($day), $ai($night)] : [$staff($day), $closed], []],
        ];

        $created = 0;
        foreach ($samples as [$name, $steps, $esc]) {
            if (WaCloudReplyRoute::where('company_id', $c)->where('name', $name)->exists()) { continue; }
            WaCloudReplyRoute::create([
                'company_id' => $c, 'wa_phone_number_id' => null, 'name' => $name, 'when_mode' => 'always', 'steps' => $steps, 'escalate_keywords' => $esc ?: null,
                'response_type' => $steps[0]['type'], 'fallback_type' => 'none', 'priority' => 0, 'is_active' => false,
            ]);
            $created++;
        }
        return response()->json(['created' => $created, 'message' => $created ? "Added {$created} sample chain(s) — paused. Open one, adjust it and press Activate." : 'Sample chains are already there.']);
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
            'name'              => [$req, 'string', 'max:120'],
            'steps'             => [$req, 'array', 'min:1', 'max:5'],
            'steps.*.type'      => ['required', Rule::in(WaCloudReplyRoute::STEP_TYPES)],
            'steps.*.flow_builder_id' => ['nullable', 'integer', Rule::exists('flow_builders', 'id')->where('company_id', $c)],
            'steps.*.playbook_id'     => ['nullable', 'integer', Rule::exists('agent_playbooks', 'id')->where('company_id', $c)],
            'steps.*.reply_text'      => ['nullable', 'string', 'max:4096'],
            'steps.*.handover'                => ['nullable', 'array'],
            'steps.*.handover.user_ids'       => ['nullable', 'array'],
            'steps.*.handover.user_ids.*'     => ['integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'steps.*.handover.ack_message'    => ['nullable', 'string', 'max:1000'],
            'steps.*.schedule'          => ['nullable', 'array'],
            'steps.*.schedule.mode'     => ['nullable', Rule::in(WaCloudReplyRoute::WHEN)],
            'steps.*.schedule.days'     => ['nullable', 'array'],
            'steps.*.schedule.start'    => ['nullable', 'date_format:H:i'],
            'steps.*.schedule.end'      => ['nullable', 'date_format:H:i'],
            'escalate_keywords'   => ['nullable', 'array'],
            'escalate_keywords.*' => ['string', 'max:60'],
            'priority'  => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        if (isset($d['steps'])) {
            foreach ($d['steps'] as $i => $st) {
                $n = $i + 1;
                abort_if($st['type'] === 'ai_agent' && empty($st['playbook_id']), 422, "Step {$n}: choose which AI agent should answer.");
                abort_if($st['type'] === 'auto_reply' && !filled($st['reply_text'] ?? null), 422, "Step {$n}: write the auto-reply message.");
                $d['steps'][$i]['schedule'] = $st['schedule'] ?? ['mode' => 'always'];
            }
            // Legacy columns mirror the first step so older readers / list views keep working.
            $first = $d['steps'][0];
            $d['response_type'] = $first['type'];
            $d['when_mode'] = 'always';
            $d['fallback_type'] = 'none';
            $d['flow_builder_id'] = $first['flow_builder_id'] ?? null;
            $d['playbook_id'] = $first['playbook_id'] ?? null;
            $d['handover'] = $first['handover'] ?? null;
            $d['reply_text'] = $first['reply_text'] ?? null;
            $d['escalate_keywords'] = array_values(array_filter(array_map('trim', $d['escalate_keywords'] ?? [])));
        }
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

        // Which position in the chain answered (1 = first responder, 2 = after one escalation …)
        $byStep = $ev()->whereNotNull('step')->where('outcome', '!=', 'failed')->select('step', DB::raw("COALESCE(final_type, response_type) as t"), DB::raw('count(*) as n'))
            ->groupBy('step', 't')->orderBy('step')->get()->groupBy('step')->map(fn ($g, $st) => ['step' => (int) $st, 'total' => (int) $g->sum('n'), 'by_type' => $g->pluck('n', 't')])->values();

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
            'by_type' => $byFinal, 'by_step' => $byStep, 'by_number' => $byNumber, 'daily' => $daily, 'handovers' => $handovers,
            'top_routes' => $topRoutes->map(fn ($x) => ['route_id' => $x->route_id, 'name' => $routeNames[$x->route_id] ?? "#{$x->route_id}", 'count' => (int) $x->n])->values(),
            'rules' => ['by_type' => $rulesByType, 'daily' => $rulesDaily,
                        'top' => $topRules->map(fn ($x) => ['rule_id' => $x->rule_id, 'name' => $ruleNames[$x->rule_id] ?? "#{$x->rule_id}", 'count' => (int) $x->n])->values()],
        ]);
    }
}
