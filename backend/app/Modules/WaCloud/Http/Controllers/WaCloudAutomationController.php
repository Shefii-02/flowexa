<?php

namespace App\Modules\WaCloud\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaCloud\Models\WaCloudAutomationLog;
use App\Modules\WaCloud\Models\WaCloudAutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD + logs for WA Cloud automation rules (own tables, own endpoints —
 * separate from the open-wa /wa-agent/automations controller).
 */
class WaCloudAutomationController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    public function index(): JsonResponse
    {
        $rules = WaCloudAutomationRule::where('company_id', $this->companyId())
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $this->companyId();

        $rule = WaCloudAutomationRule::create($data);

        return response()->json($rule, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->find($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = $this->find($id);
        $rule->update($this->validated($request, isUpdate: true));

        return response()->json($rule);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $rule = $this->find($id);
        $rule->update(['is_active' => !$rule->is_active]);

        return response()->json($rule);
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = WaCloudAutomationLog::where('company_id', $this->companyId())
            ->when($request->filled('rule_id'), fn ($q) => $q->where('rule_id', $request->integer('rule_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('wa_phone_number_id'), fn ($q) => $q->where('wa_phone_number_id', $request->integer('wa_phone_number_id')))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }

    /**
     * POST /wa-cloud/automations/seed-samples — one worked example per rule type so a new company can see how each is
     * set up. Created PAUSED (nothing fires until the owner reviews and activates), skipped if a sample of that name
     * already exists, and always covering all numbers so they can be narrowed in the editor.
     */
    public function seedSamples(): JsonResponse
    {
        $cid = $this->companyId();
        $samples = [
            ['welcome_message', 'Sample – Welcome new customers', [], ['send_message' => "Hi 👋 Welcome! Thanks for messaging us.\nHow can we help you today?"], []],
            ['out_of_office', 'Sample – After-hours auto reply', [],
                ['send_message' => "We're closed right now 🌙\nOur team replies Mon–Fri, 9 AM – 6 PM. Leave your message and we'll get back to you first thing."],
                ['schedule_start' => '09:00', 'schedule_end' => '18:00', 'schedule_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'delay_hours' => 6, 'extra' => [['type' => 'create_task', 'title' => 'Reply to after-hours message', 'due_hours' => 12]]]],
            ['lead_qualifier', 'Sample – Qualify pricing enquiries', ['price', 'pricing', 'quote', 'cost', 'demo', 'buy'],
                ['send_message' => "Thanks for your interest! 🙌 To help you faster, could you share your name, city and what you're looking for?"],
                ['extra' => [['type' => 'assign_agent', 'user_ids' => []], ['type' => 'mark_lead', 'stage' => 'contacted']]]],
            ['keyword_trigger', 'Sample – Opening hours & address', ['hours', 'timing', 'open', 'address', 'location'],
                ['send_message' => "📍 Visit us: <your address>\n🕘 Open Mon–Sat, 9 AM – 7 PM\nReply *AGENT* to talk to a person."], []],
            ['follow_up_reminder', 'Sample – Gentle reminder after 1 day', [],
                ['send_message' => 'Just checking in 😊 Is there anything else we can help you with?'], ['delay_hours' => 24]],
            ['follow_up_agent', 'Sample – Alert the agent after 2 days of silence', [],
                [], ['delay_hours' => 48, 'extra' => [['type' => 'notify_agent', 'user_ids' => [], 'title' => 'Customer has not replied for 2 days'], ['type' => 'create_task', 'title' => 'Follow up with customer', 'due_hours' => 24]]]],
            ['inactivity_trigger', 'Sample – Re-engage after 3 days', [],
                ['send_message' => "Hi! We haven't heard from you in a while. Still interested? Reply YES and we'll pick up where we left off."], ['inactivity_hours' => 72]],
        ];

        $created = 0;
        foreach ($samples as [$type, $name, $keywords, $msg, $opts]) {
            if (WaCloudAutomationRule::where('company_id', $cid)->where('name', $name)->exists()) { continue; }
            $actions = [];
            if (!empty($msg['send_message'])) { $actions[] = ['type' => 'send_message', 'message' => $msg['send_message']]; }
            foreach (($opts['extra'] ?? []) as $a) { $actions[] = $a; }
            WaCloudAutomationRule::create([
                'company_id' => $cid, 'wa_phone_number_id' => null, 'rule_type' => $type, 'name' => $name,
                'keywords' => $keywords ?: null, 'actions' => $actions, 'priority' => 0, 'is_active' => false,
                'schedule_start' => $opts['schedule_start'] ?? null, 'schedule_end' => $opts['schedule_end'] ?? null, 'schedule_days' => $opts['schedule_days'] ?? null,
                'delay_hours' => $opts['delay_hours'] ?? null, 'inactivity_hours' => $opts['inactivity_hours'] ?? null,
            ]);
            $created++;
        }
        return response()->json(['created' => $created, 'message' => $created ? "Added {$created} sample rule(s) — paused. Review, edit and activate the ones you want." : 'Sample rules are already there.']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function find(int $id): WaCloudAutomationRule
    {
        return WaCloudAutomationRule::where('company_id', $this->companyId())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'wa_phone_number_id' => ['nullable', 'integer', Rule::exists('wa_phone_numbers', 'id')->where('company_id', $this->companyId())],
            'rule_type'          => [$req, Rule::in(WaCloudAutomationRule::RULE_TYPES)],
            'name'               => [$req, 'string', 'max:150'],
            'conditions'         => ['nullable', 'array'],
            'actions'            => [$req, 'array', 'min:1'],
            'keywords'           => ['nullable', 'array'],
            'priority'           => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active'          => ['boolean'],
            'schedule_start'     => ['nullable', 'date_format:H:i'],
            'schedule_end'       => ['nullable', 'date_format:H:i'],
            'schedule_days'      => ['nullable', 'array'],
            'delay_hours'        => ['nullable', 'integer', 'min:1'],
            'inactivity_hours'   => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
