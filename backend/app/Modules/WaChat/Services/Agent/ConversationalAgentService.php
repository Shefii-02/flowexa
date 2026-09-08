<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Jobs\AnalyzeConversation;
use App\Models\Contact;
use App\Models\Lead;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaChat\Models\AiAgentSession;
use App\Modules\WaChat\Models\AutomationLog;
use App\Modules\WaChat\Services\Rag\EvidenceCollector;
use App\Modules\WaChat\Services\Rag\PlannerAgent;
use Illuminate\Support\Facades\Log;

/**
 * The channel-neutral brain: greeting → guided qualification (slot filling with
 * knowledge-base answers interleaved) → lead handoff. One entry point, called by
 * both the open-wa and Meta Cloud webhooks.
 */
class ConversationalAgentService
{
    public function __construct(
        private readonly AgentGatewayFactory $gateways,
        private readonly AgentTurnPlanner    $planner,
        private readonly EvidenceCollector   $evidence,
        private readonly PlannerAgent        $subQueryPlanner,
    ) {}

    /** @return bool  true if the agent handled the message (caller should stop). */
    public function handle(AgentInbound $in): bool
    {
        if ($in->fromGroup || !$in->hasText()) {
            return false;
        }

        $playbook = AgentPlaybook::resolveFor($in->companyId, $in->sessionRef);
        if (!$playbook) {
            return false;
        }

        try {
            return $this->run($in, $playbook);
        } catch (\Throwable $e) {
            Log::error("ConversationalAgentService error (company {$in->companyId}): " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    private function run(AgentInbound $in, AgentPlaybook $playbook): bool
    {
        $gateway = $this->gateways->for($in->channel);

        [$session, $isNew] = $this->resumeOrOpenSession($in, $playbook);

        // ── New conversation: greet, open a lead, ask the first question ──────
        if ($isNew) {
            $contact   = $this->ensureContactAndLead($in);
            $returning  = $this->isReturning($in, $session->id);
            $greeting   = $returning
                ? ($playbook->greeting_returning ?: $playbook->greeting_new)
                : ($playbook->greeting_new ?: "Hi! 👋 How can I help you today?");

            $this->sendAndRecord($gateway, $in, $session, $greeting, 'assistant');
            $this->log($in, $playbook, 'greeting_sent', ['returning' => $returning]);
        }

        // ── Escalation keywords: hand to a human immediately ─────────────────
        if ($this->matchesEscalationKeyword($playbook, $in->text)) {
            return $this->escalate($gateway, $in, $session, $playbook, 'keyword');
        }

        // ── Retrieve knowledge-base context for this message ─────────────────
        $subQueries = $this->subQueryPlanner->decompose($in->text);
        $found      = $this->evidence->collect($subQueries, $in->companyId);
        $kbContext  = $this->evidence->buildContext($found);

        // ── One LLM call: reply + slot extraction + flags ────────────────────
        $slots = $session->collected_slots ?? [];
        $plan  = $this->planner->plan(
            company:        $session->company,
            playbook:       $playbook,
            collectedSlots: $slots,
            history:        $session->conversation_history ?? [],
            userMessage:    $in->text,
            kbContext:      $kbContext,
        );

        if (!$plan) {
            $fallback = $playbook->fallback_transfer_message
                ?: "Thanks for your message — a team member will get back to you shortly.";
            $this->sendAndRecord($gateway, $in, $session, $fallback, 'assistant', $in->text);
            $this->log($in, $playbook, 'llm_unavailable', []);
            return true;
        }

        // Merge newly extracted slots (never overwrite an existing answer).
        foreach ($plan['extracted_slots'] as $key => $value) {
            if ($value !== '' && $value !== null && !array_key_exists($key, $slots)) {
                $slots[$key] = is_scalar($value) ? (string) $value : json_encode($value);
            }
        }

        $session->fill([
            'collected_slots'  => $slots,
            'detected_language'=> $plan['language'] ?: $session->detected_language,
            'detected_style'   => $plan['style'] ?: $session->detected_style,
        ]);

        $this->sendAndRecord($gateway, $in, $session, $plan['reply'], 'assistant', $in->text);

        // ── Outcomes ────────────────────────────────────────────────────────
        if ($plan['wants_human']) {
            return $this->escalate($gateway, $in, $session, $playbook, 'requested', sendMessage: false);
        }

        $missing = $playbook->missingRequiredSlots($slots);
        if ($plan['qualification_done'] || empty($missing)) {
            $this->completeQualification($in, $session, $playbook);
        } else {
            $session->update(['qualification_status' => 'in_progress']);
        }

        return true;
    }

    // ── Session ─────────────────────────────────────────────────────────────

    private function resumeOrOpenSession(AgentInbound $in, AgentPlaybook $playbook): array
    {
        $session = AiAgentSession::where('company_id', $in->companyId)
            ->where('waha_session_id', $in->sessionRef)
            ->where('contact_phone', $in->phone)
            ->where('status', 'active')
            ->latest()
            ->first();

        if ($session) {
            return [$session, false];
        }

        $session = AiAgentSession::create([
            'company_id'           => $in->companyId,
            'playbook_id'          => $playbook->id,
            'waha_session_id'      => $in->sessionRef,
            'contact_phone'        => $in->phone,
            'status'               => 'active',
            'conversation_history' => [],
            'collected_slots'      => [],
            'qualification_status' => 'in_progress',
            'ai_config'            => ['channel' => $in->channel],
            'last_message_at'      => now(),
        ]);

        return [$session, true];
    }

    private function isReturning(AgentInbound $in, int $currentSessionId): bool
    {
        return AiAgentSession::where('company_id', $in->companyId)
            ->where('contact_phone', $in->phone)
            ->where('id', '!=', $currentSessionId)
            ->exists();
    }

    // ── Sending + history ───────────────────────────────────────────────────

    private function sendAndRecord(
        $gateway, AgentInbound $in, AiAgentSession $session,
        string $reply, string $role, ?string $userMessage = null
    ): void {
        $gateway->sendText($in->companyId, $in->sessionRef, $in->phone, $reply);

        $history = $session->conversation_history ?? [];
        if ($userMessage !== null) {
            $history[] = ['role' => 'user', 'content' => $userMessage];
        }
        $history[] = ['role' => $role, 'content' => $reply];
        if (count($history) > 24) {
            $history = array_slice($history, -24);
        }

        $session->conversation_history = $history;
        $session->last_message_at      = now();
        $session->save();
    }

    // ── Contact + Lead ──────────────────────────────────────────────────────

    private function ensureContactAndLead(AgentInbound $in): Contact
    {
        $contact = Contact::firstOrCreate(
            ['company_id' => $in->companyId, 'phone' => $in->phone],
            ['name' => $in->phone, 'source' => 'whatsapp_ai'],
        );

        $hasOpenLead = Lead::where('company_id', $in->companyId)
            ->where('contact_id', $contact->id)
            ->whereNotIn('stage', ['enrolled', 'lost', 'disqualified'])
            ->exists();

        if (!$hasOpenLead) {
            Lead::create([
                'company_id' => $in->companyId,
                'contact_id' => $contact->id,
                'stage'      => 'new',
                'source'     => 'whatsapp_ai',
                'notes'      => 'Auto-created by AI Agent on first WhatsApp message.',
            ]);
        }

        return $contact;
    }

    private function completeQualification(AgentInbound $in, AiAgentSession $session, AgentPlaybook $playbook): void
    {
        $session->update(['qualification_status' => 'complete']);

        $handoff = $playbook->handoff ?? [];
        $contact = Contact::where('company_id', $in->companyId)->where('phone', $in->phone)->first();
        if (!$contact) {
            return;
        }

        $lead = Lead::where('company_id', $in->companyId)
            ->where('contact_id', $contact->id)
            ->whereNotIn('stage', ['enrolled', 'lost', 'disqualified'])
            ->latest()
            ->first();

        if ($lead) {
            $summary = collect($session->collected_slots ?? [])
                ->map(fn($v, $k) => "• {$k}: {$v}")
                ->implode("\n");

            $lead->update([
                'stage'    => $handoff['lead_stage'] ?? $lead->stage ?? 'qualified',
                'category' => $handoff['lead_category'] ?? $lead->category,
                'notes'    => trim(($lead->notes ? $lead->notes . "\n\n" : '')
                    . "AI Agent qualification:\n" . $summary),
            ]);
        }

        // Lead-intelligence scoring on the now-complete transcript.
        $lastUser = collect($session->conversation_history ?? [])
            ->last(fn($t) => ($t['role'] ?? '') === 'user')['content'] ?? $in->text;
        AnalyzeConversation::dispatch($in->companyId, $contact->id, $in->phone, $lastUser, $in->sessionRef)
            ->onQueue('analysis');

        $this->log($in, $playbook, 'qualification_complete', ['slots' => $session->collected_slots]);

        // Phase 3 will add: staff assignment, notifications, task creation,
        // payment link. For now the lead + score + closing message are live.
    }

    // ── Escalation ──────────────────────────────────────────────────────────

    private function matchesEscalationKeyword(AgentPlaybook $playbook, string $text): bool
    {
        $keywords = $playbook->escalation['keywords'] ?? [];
        $lower    = mb_strtolower($text);

        foreach ($keywords as $kw) {
            if ($kw && str_contains($lower, mb_strtolower($kw))) {
                return true;
            }
        }

        return false;
    }

    private function escalate($gateway, AgentInbound $in, AiAgentSession $session, AgentPlaybook $playbook, string $reason, bool $sendMessage = true): bool
    {
        if ($sendMessage) {
            $msg = $playbook->escalation['on_escalate_message']
                ?? "One moment — I'm connecting you with our team now.";
            $this->sendAndRecord($gateway, $in, $session, $msg, 'assistant', $in->text);
        }

        $session->update([
            'status'               => 'transferred',
            'qualification_status' => 'escalated',
        ]);

        $this->log($in, $playbook, 'escalated', ['reason' => $reason]);

        return true;
    }

    // ── Logs (visible in WA Agent → Logs) ──────────────────────────────────

    private function log(AgentInbound $in, AgentPlaybook $playbook, string $event, array $data): void
    {
        try {
            AutomationLog::create([
                'company_id'    => $in->companyId,
                'rule_id'       => null,
                'session_id'    => $in->sessionRef,
                'contact_phone' => $in->phone,
                'rule_type'     => 'ai_agent',
                'trigger_data'  => ['event' => $event, 'channel' => $in->channel] + $data,
                'action_taken'  => $event,
                'status'        => 'success',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ConversationalAgentService: log write failed: ' . $e->getMessage());
        }
    }
}
