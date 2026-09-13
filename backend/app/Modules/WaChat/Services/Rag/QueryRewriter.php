<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Rewrites the customer's raw message into a clear, correctly-spelled, complete question
 * BEFORE it's used for knowledge-base retrieval — the "rewrite (correct what was asked) then
 * search" step. The hand-coded fixes elsewhere in this pipeline (stemming, Manglish word
 * lists, numbered-list reference resolution) only catch the specific patterns they were
 * written for; a real customer can misspell, abbreviate, or garble a question in ways no
 * static rule anticipates. An LLM rewrite generalizes past that — it's used purely to build
 * the SEARCH query, never sent to the customer and never treated as what they "really said"
 * for conversation history, so a bad rewrite can only ever hurt retrieval, not the actual
 * reply the customer sees.
 *
 * On any failure (no AI key, API error) this returns null — the caller then searches with
 * whatever query it already had, exactly as before this existed. This is a best-effort
 * enhancement layered on top of the existing pipeline, not a dependency it now requires.
 */
class QueryRewriter
{
    private const SYSTEM_PROMPT = <<<TXT
Rewrite the customer's latest WhatsApp message into a single, clear, correctly-spelled,
grammatically complete question or statement in English, capturing exactly what they are
asking about — nothing more, nothing less. Fix typos and expand shorthand/abbreviations
(e.g. "u" -> "you", "wat" -> "what", "eniku vila ariyanam" (Manglish for "I want to know
the price") -> "What is the price?"). Resolve an obvious reference to the assistant's own
previous message (e.g. "i choosed 3" after a numbered list -> name what option 3 actually
was). Do NOT answer the question. Do NOT add information the message didn't imply. If the
message is already clear, return it almost unchanged.

Respond with ONLY the rewritten question/statement — no quotes, no explanation, no JSON.
TXT;

    public function __construct(private readonly LlmClient $llm) {}

    public function rewrite(Company $company, string $query, array $history): ?string
    {
        $messages = [];
        foreach (array_slice($history, -4) as $turn) {
            $role       = ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($turn['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $query];

        try {
            $raw = $this->llm->chat($company, self::SYSTEM_PROMPT, $messages, 120);
        } catch (\Throwable $e) {
            Log::warning('QueryRewriter: chat call threw: ' . $e->getMessage());
            return null;
        }

        if ($raw === null) {
            return null;
        }

        $rewritten = trim($raw, " \t\n\r\0\x0B\"'“”");
        return $rewritten !== '' ? $rewritten : null;
    }
}
