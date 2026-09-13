<?php

namespace App\Modules\WaChat\Services\Rag;

class PlannerAgent
{
    private const ORDINAL_WORDS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
    ];

    /**
     * A bare greeting ("hi", "hello", "hai", "good morning") is the single most common
     * first message on WhatsApp, and it shares essentially no words with any knowledge base
     * content — running it through RAG retrieval always fails and returns the generic
     * "couldn't find an answer" fallback, which reads as broken even though nothing actually
     * went wrong. Covers common English forms plus a few frequently-used non-English/Manglish
     * greetings; anything with real content attached ("hi, what's your pricing") correctly
     * falls through to normal RAG since not every word in the message is a greeting word.
     */
    private const GREETING_WORDS = [
        'hi', 'hii', 'hiii', 'hello', 'hey', 'heya', 'hai', 'yo', 'sup',
        'greetings', 'morning', 'afternoon', 'evening', 'good', 'namaste', 'salam',
        'assalamualaikum', 'vanakkam', 'namaskar', 'namaskaram',
    ];

    /**
     * When the user's message references a numbered item from the assistant's own last
     * reply ("i choosed 3", "tell me about option 2", "the third one") — a common WhatsApp
     * pattern right after the agent lists several options — this pulls that specific list
     * item's own text out of the previous reply so it can be blended into the retrieval
     * query. On its own, a message like "i choosed 3" shares essentially no words with the
     * knowledge base — the actual topic (e.g. "WhatsApp & AI Automation") only exists in the
     * assistant's own prior message — so without this, retrieval had nothing to match and
     * always fell back to the generic "couldn't find an answer" reply, even though the
     * assistant had just listed exactly what was being asked about. Returns null (no
     * behavior change) whenever there's no number/ordinal in the query, or the assistant's
     * last message wasn't actually a numbered list — this only ever fires for that specific,
     * well-scoped pattern, not for short replies in general.
     */
    public function resolveListReference(string $query, array $history): ?string
    {
        $lower  = mb_strtolower($query);
        $number = null;

        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\b/', $lower, $m)) {
            $number = (int) $m[1];
        } else {
            foreach (self::ORDINAL_WORDS as $word => $n) {
                if (str_contains($lower, $word)) { $number = $n; break; }
            }
        }

        if (!$number) return null;

        $lastAssistantMsg = collect($history)->where('role', 'assistant')->last()['content'] ?? null;
        if (!$lastAssistantMsg) return null;

        if (preg_match('/^\s*\**' . $number . '\**[\.\):]\s*(.+)$/mu', $lastAssistantMsg, $m)) {
            return trim(strip_tags($m[1]));
        }

        return null;
    }

    /**
     * True only when EVERY word in the message is a greeting word — "hi" or "good morning"
     * qualifies, but "hi, what's your pricing" does not, because "pricing" isn't one. That
     * keeps this from swallowing a real question that merely opens with a greeting.
     */
    public function isGreeting(string $query): bool
    {
        $words = preg_split('/\W+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) return false;

        foreach ($words as $word) {
            if (!in_array($word, self::GREETING_WORDS, true)) return false;
        }

        return true;
    }

    /**
     * Decompose the user query into sub-queries for broader retrieval coverage.
     * Simple heuristic: extract noun phrases and question variants.
     */
    public function decompose(string $query): array
    {
        $queries = [$query];

        // Strip question words to get the core topic
        $stripped = preg_replace('/^(what|how|why|when|where|who|can|could|should|is|are|do|does)\s+/i', '', $query);
        if ($stripped !== $query) {
            $queries[] = trim($stripped);
        }

        // Add keyword extraction variant (just nouns/meaningful words)
        $keywords = $this->extractKeywords($query);
        if (!empty($keywords)) {
            $queries[] = implode(' ', $keywords);
        }

        return array_unique(array_filter($queries));
    }

    public function classifyIntent(string $query): string
    {
        $lower = mb_strtolower($query);

        if (preg_match('/\b(price|cost|fee|charge|rate|plan|package|subscription)\b/', $lower)) return 'pricing';
        if (preg_match('/\b(how to|steps|guide|setup|configure|install)\b/', $lower))           return 'how_to';
        if (preg_match('/\b(error|problem|issue|not working|broken|fix|fail)\b/', $lower))       return 'troubleshooting';
        if (preg_match('/\b(contact|phone|email|address|location|office)\b/', $lower))           return 'contact';
        if (preg_match('/\b(feature|what is|what are|explain|describe)\b/', $lower))             return 'information';

        return 'general';
    }

    private function extractKeywords(string $text): array
    {
        $stopWords = ['what','how','why','when','where','who','can','could','should',
                      'is','are','do','does','the','a','an','and','or','for','in','on',
                      'at','to','of','with','this','that','i','my','me','please','help'];

        // [^\p{L}\p{M}\p{N}]+ (not \W+) keeps Devanagari/Malayalam/etc. words intact across
        // their combining vowel signs — see QueryAgent::buildVector() for the full rationale.
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn($w) =>
            strlen($w) >= 3 && !in_array($w, $stopWords)
        ));
    }
}
