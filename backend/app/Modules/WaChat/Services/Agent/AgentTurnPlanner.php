<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Models\Company;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaChat\Services\Rag\LlmClient;
use Illuminate\Support\Facades\Log;

/**
 * One LLM call per customer turn. Given the playbook, the slots collected so
 * far and the knowledge-base context, it returns BOTH the reply to send (in the
 * customer's own language and style) AND the structured outcome of the turn.
 */
class AgentTurnPlanner
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @param array<array{role:string,content:string}> $history
     * @return array{
     *   reply: string,
     *   language: ?string,
     *   style: ?string,
     *   extracted_slots: array<string,string>,
     *   wants_human: bool,
     *   qualification_done: bool
     * }|null
     */
    public function plan(
        Company $company,
        AgentPlaybook $playbook,
        array $collectedSlots,
        array $history,
        string $userMessage,
        string $kbContext
    ): ?array {
        $system   = $this->systemPrompt($playbook, $collectedSlots, $kbContext);
        $messages = $this->messages($history, $userMessage);

        $raw = $this->llm->chat($company, $system, $messages, 700);
        if ($raw === null) {
            return null;
        }

        $parsed = $this->parseJson($raw);
        if (!$parsed || empty($parsed['reply'])) {
            // The model answered in prose instead of JSON — still usable as a reply.
            return [
                'reply'              => trim($raw),
                'language'           => null,
                'style'              => null,
                'extracted_slots'    => [],
                'wants_human'        => false,
                'qualification_done' => false,
            ];
        }

        return [
            'reply'              => trim((string) $parsed['reply']),
            'language'           => $parsed['language'] ?? null,
            'style'              => $parsed['style'] ?? null,
            'extracted_slots'    => is_array($parsed['extracted_slots'] ?? null) ? $parsed['extracted_slots'] : [],
            'wants_human'        => (bool) ($parsed['wants_human'] ?? false),
            'qualification_done' => (bool) ($parsed['qualification_done'] ?? false),
        ];
    }

    private function systemPrompt(AgentPlaybook $playbook, array $slots, string $kbContext): string
    {
        $questions = $playbook->qualification_questions ?? [];
        $missing   = $playbook->missingRequiredSlots($slots);

        $qLines = [];
        foreach ($questions as $q) {
            $key      = $q['key'] ?? '';
            $have     = array_key_exists($key, $slots) ? " [collected: \"{$slots[$key]}\"]" : '';
            $req      = ($q['required'] ?? true) ? 'required' : 'optional';
            $opts     = !empty($q['options']) ? ' (options: ' . implode(', ', $q['options']) . ')' : '';
            $qLines[] = "- {$key} ({$req}): {$q['question']}{$opts}{$have}";
        }

        $agentName = $playbook->agent_name ?: 'AI Assistant';
        $base      = $playbook->system_prompt ?: "You are {$agentName}, a helpful WhatsApp assistant.";

        $prompt = <<<TXT
{$base}

YOUR TASK THIS TURN
You are running a guided qualification conversation on WhatsApp. Collect the
fields below by asking ONE question at a time, in a natural order. Never ask for
a field that is already collected. If the customer's message answers several
fields at once, capture them all.

FIELDS TO COLLECT:
{$this->join($qLines)}

STILL MISSING (required): {$this->missingLabel($missing)}

RULES
- If the customer asks a question that the KNOWLEDGE BASE below can answer, answer
  it briefly first, then continue with the next missing field.
- If the KNOWLEDGE BASE cannot answer it, say a team member will follow up with
  the exact details, then continue.
- When every REQUIRED field is collected, set "qualification_done": true and make
  "reply" a short closing/confirmation message.
- If the customer clearly asks for a human / phone call / to speak to an agent,
  set "wants_human": true.
- Detect the customer's language and writing style (native script, romanised such
  as Manglish/Hinglish, or code-mixed) and write "reply" in that SAME language
  and style. Keep it short and WhatsApp-friendly.

KNOWLEDGE BASE:
{$this->kb($kbContext)}

RESPOND WITH ONLY THIS JSON (no markdown fences, no extra text):
{
  "reply": "the message to send the customer, in their language and style",
  "language": "detected language name",
  "style": "native | romanised | mixed | english",
  "extracted_slots": { "field_key": "value" },
  "wants_human": false,
  "qualification_done": false
}
TXT;

        return $prompt;
    }

    private function messages(array $history, string $userMessage): array
    {
        $messages = [];
        foreach (array_slice($history, -10) as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($turn['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    private function parseJson(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $clean);
        $clean = preg_replace('/\s*```$/m', '', $clean);

        // Grab the outermost { ... } if the model added stray prose.
        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $clean = $m[0];
        }

        $decoded = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('AgentTurnPlanner: JSON parse failed: ' . mb_substr($raw, 0, 300));
            return null;
        }

        return $decoded;
    }

    private function join(array $lines): string
    {
        return $lines ? implode("\n", $lines) : '(none)';
    }

    private function missingLabel(array $missing): string
    {
        return $missing ? implode(', ', $missing) : 'none — all required fields collected';
    }

    private function kb(string $context): string
    {
        return trim($context) !== '' ? $context : '(no relevant knowledge base entries)';
    }
}
