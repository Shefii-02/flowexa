<?php

namespace App\Modules\Instagram\Services;

use App\Models\InstagramAccount;
use App\Models\InstagramConversation;
use App\Modules\Catalog\AgentContext;
use App\Modules\Catalog\IndustryTemplates;
use App\Modules\Catalog\LeadIntake;
use App\Modules\WaChat\Services\Rag\LlmClient;
use Illuminate\Support\Facades\Log;

/**
 * The Instagram DM auto-responder. After the keyword automation sends its first DM, the customer's
 * follow-up messages land here: the agent answers from the company knowledge base + live catalog,
 * mirrors the customer's style, works toward a phone number, and the moment it has one it creates a
 * routed CRM lead (staff assignment, notification, SLA follow-up) via {@see LeadIntake}.
 *
 * Runs only when: account + thread AI enabled, inside Instagram's 24h window, and an LLM key is set.
 */
class InstagramAiAgent
{
    private const HISTORY_TURNS = 14;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly AgentContext $context,
        private readonly LeadIntake $intake,
        private readonly InstagramConversationService $conversations,
    ) {}

    public function shouldHandle(InstagramAccount $account, InstagramConversation $convo): bool
    {
        return $account->ai_enabled
            && $account->is_active
            && $convo->ai_enabled
            && $convo->status !== 'closed'
            && $convo->withinMessagingWindow()
            && $this->llm->isConfigured($account->company);
    }

    public function handle(InstagramAccount $account, InstagramConversation $convo): void
    {
        if (!$this->shouldHandle($account, $convo)) {
            return;
        }

        $history = $convo->messages()
            ->latest()->limit(self::HISTORY_TURNS)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'content' => (string) ($m->text ?: '[media]')])
            ->values()->all();

        if (empty($history) || $history[array_key_last($history)]['role'] !== 'user') {
            return;
        }
        $lastMessage = $history[array_key_last($history)]['content'];

        $company  = $account->company;
        $template = $company->industry_template ?: 'generic';
        $context  = $this->context->build($company, $lastMessage, $template);

        $raw = $this->llm->chat($company, $this->systemPrompt($account, $template, $convo, $context), $history, 500);
        $parsed = $this->parse($raw);
        if (!$parsed) {
            return; // leave the thread for a human rather than send noise
        }

        // Merge anything newly learned.
        $collected = array_merge($convo->collected ?? [], array_filter(
            $parsed['collected'] ?? [],
            fn ($v) => $v !== null && $v !== '',
        ));
        if ($convo->participant_username && empty($collected['name'])) {
            // fall back to the IG handle only for the note, not as a real name
        }
        $convo->collected = $collected;
        $convo->save();

        // Phone captured → routed CRM lead (once).
        if (!$convo->lead_id && !empty($collected['phone'])) {
            $result = $this->intake->capture($company, $collected, 'instagram_dm', "ig:{$convo->id}");
            if ($result['contact']) {
                $convo->update([
                    'contact_id'      => $result['contact']->id,
                    'lead_id'         => $result['lead']?->id,
                    'lead_created_at' => now(),
                ]);
            }
        }

        $reply = trim((string) ($parsed['reply'] ?? ''));
        if ($reply === '' || str_contains(mb_strtolower($reply), '[handoff]')) {
            $convo->update(['ai_enabled' => false]);
            return;
        }

        $this->conversations->send($convo->fresh(), $reply, 'ai');
    }

    // ── prompt ───────────────────────────────────────────────────────────

    private function systemPrompt(InstagramAccount $account, string $template, InstagramConversation $convo, string $context): string
    {
        $company = $account->company->name;
        $tpl     = IndustryTemplates::get($template);
        $persona = trim((string) $account->ai_persona) ?: "a helpful representative of {$company}";
        $style   = $account->mirror_customer_style
            ? "Mirror the customer's language, tone, formality, emoji use and message length. Short if they're short. "
              . "Reply in their language (Hinglish/Manglish/etc. as written)."
            : 'Keep a warm, professional tone.';
        $qual = collect($tpl['qualification_fields'] ?? [])
            ->map(fn ($f) => "  - {$f['key']} ({$f['label']})" . (!empty($f['required']) ? ' [required]' : ''))
            ->implode("\n");
        $already = json_encode($convo->collected ?? (object) [], JSON_UNESCAPED_UNICODE);
        $extra   = $tpl['agent_prompt'] ?? '';

        return <<<PROMPT
        You are {$persona}, replying to a customer in an Instagram Direct Message. {$extra}

        Style: {$style}
        DM etiquette: 1-3 short sentences, no email greetings or sign-offs, one question at a time.

        Answer using ONLY the facts below. Never invent prices, availability, links, addresses or
        policies. If something isn't covered, say you'll check and ask for their phone number so the
        team can follow up.

        {$context}

        Naturally work toward collecting these (don't interrogate — one at a time, in context):
        {$qual}
        A phone number is the priority — once you have their requirement, ask for it so the team can
        share options / confirm.

        Already collected: {$already} — never ask again for anything already there.

        Respond with STRICT JSON only, no markdown:
        {
          "reply": "your next DM to the customer",
          "collected": { "<field>": "<value>" }   // only fields learned in the customer's LAST message
        }
        If the customer wants to buy/book/pay, complains, or asks for a human, set "reply" to exactly "[HANDOFF]".
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
}
