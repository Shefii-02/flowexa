<?php

namespace App\Modules\Catalog;

use App\Models\ChatWidget;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Modules\WaChat\Services\Rag\LlmClient;
use Illuminate\Support\Facades\Log;

/**
 * The brain behind the website chat widget. One LLM call per visitor message:
 *   - it has the company's live catalog + the vertical's question flow + what's already been collected
 *   - it returns JSON: { reply, collected:{field:value}, requirements:{...} }
 * We merge the collected fields, run the {@see LeadQualifier}, fire alerts on a fresh qualification,
 * and hand back the reply.
 */
class WidgetChatAgent
{
    private const HISTORY_TURNS = 16;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ListingMatcher $matcher,
        private readonly LeadQualifier $qualifier,
        private readonly LeadAlertService $alerts,
        private readonly AgentContext $context,
    ) {}

    public function isConfigured(ChatWidget $widget): bool
    {
        return $widget->company && $this->llm->isConfigured($widget->company);
    }

    public function reply(ChatWidget $widget, WidgetConversation $convo, string $visitorMessage): string
    {
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => $visitorMessage]);
        $convo->update(['last_message_at' => now()]);

        $template = $widget->resolvedTemplate();
        $tpl = IndustryTemplates::get($template);

        if (!$this->isConfigured($widget)) {
            return $this->fallback($convo, "Thanks! Someone from our team will get back to you shortly. "
                . 'Could you share your name and phone number so we can reach you?');
        }

        $history = $convo->messages()
            ->latest()->limit(self::HISTORY_TURNS)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role === 'visitor' ? 'user' : 'assistant', 'content' => $m->text])
            ->values()->all();

        // Knowledge base + live catalog, focused on what the visitor just asked.
        $context = $this->context->build($widget->company, $visitorMessage, $template);

        $raw = $this->llm->chat($widget->company, $this->systemPrompt($widget, $tpl, $convo, $context), $history, 700);
        $parsed = $this->parse($raw);

        if (!$parsed) {
            return $this->fallback($convo, "Sorry, I didn't quite catch that — could you rephrase?");
        }

        // Merge newly collected fields.
        $collected = array_merge($convo->collected ?? [], array_filter(
            $parsed['collected'] ?? [],
            fn ($v) => $v !== null && $v !== '',
        ));
        $convo->collected = $collected;

        // Record which listings matched the stated requirements (for the dashboard + future turns).
        if (!empty($parsed['requirements']) && is_array($parsed['requirements'])) {
            $matches = $this->matcher->match($widget->company_id, $template, $parsed['requirements'], 3);
            $convo->matched_listing_ids = $matches->pluck('listing.id')->all();
        }
        $convo->save();

        // Qualify + alert on the transition.
        $lead = $this->qualifier->qualifyWidgetConversation($convo->fresh(), $template);
        if ($convo->fresh()->status === 'qualified' && $lead !== null) {
            $widget->increment('leads_count');
            try {
                $this->alerts->notifyQualified($widget, $convo->fresh(), $lead);
            } catch (\Throwable $e) {
                Log::warning('WidgetChatAgent: alert dispatch failed', ['error' => $e->getMessage()]);
            }
        }

        $reply = trim((string) ($parsed['reply'] ?? '')) ?: 'Got it — anything else I can help with?';
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $reply]);
        return $reply;
    }

    // ── prompt / parsing ─────────────────────────────────────────────────

    private function systemPrompt(ChatWidget $widget, array $tpl, WidgetConversation $convo, string $context): string
    {
        $company = $widget->company->name;
        $agent   = $widget->agent_name ?: 'Assistant';
        $flow    = collect($tpl['question_flow'] ?? [])->map(fn ($q, $i) => ($i + 1) . '. ' . $q)->implode("\n");
        $qual    = collect($tpl['qualification_fields'] ?? [])
            ->map(fn ($f) => "  - {$f['key']} ({$f['label']})" . (!empty($f['required']) ? ' [required]' : ''))
            ->implode("\n");
        $already = json_encode($convo->collected ?? (object) [], JSON_UNESCAPED_UNICODE);
        $extra   = $tpl['agent_prompt'] ?? '';

        return <<<PROMPT
        You are {$agent}, the assistant on {$company}'s website chat. {$extra}

        Behave like a sharp, friendly human on live chat — short messages, no corporate tone, one
        question at a time. Read what the visitor actually asked and answer it using ONLY the facts
        below. Never invent prices, availability, addresses or policies.

        {$context}

        Work naturally toward collecting these lead fields (don't interrogate — weave them in):
        {$qual}
        Suggested flow:
        {$flow}
        A phone number is the priority once you understand what they want.

        Already collected this chat: {$already}
        — do not ask again for anything already there.

        Respond with STRICT JSON only, no markdown:
        {
          "reply": "your next message to the visitor",
          "collected": { "<field>": "<value>", ... },   // only fields you learned in the LAST visitor message
          "requirements": { "budget": number, "location": string, ... }  // the visitor's stated needs, for catalog matching; omit if none
        }
        PROMPT;
    }

    private function parse(?string $raw): ?array
    {
        if (!$raw) return null;
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        if (preg_match('/\{.*\}/s', $raw, $m)) $raw = $m[0];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    private function fallback(WidgetConversation $convo, string $text): string
    {
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $text]);
        return $text;
    }
}
