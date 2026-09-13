<?php

namespace App\Modules\WaChat\Services\Rag;

class Verifier
{
    private const MIN_RELEVANCE_SCORE = 0.08;

    /**
     * Verify that retrieved evidence is sufficiently relevant to the query.
     * Returns true if context is good enough to generate a response.
     */
    public function verify(array $evidence, string $query): bool
    {
        if (empty($evidence)) return false;

        $topScore = $evidence[0]['score'] ?? 0.0;
        if ($topScore < self::MIN_RELEVANCE_SCORE) return false;

        // Check that at least one evidence chunk shares key terms with query
        $queryTerms = $this->tokenize($query);
        foreach ($evidence as $item) {
            $chunkTerms = $this->tokenize($item['chunk']->content);
            $overlap    = count(array_intersect($queryTerms, $chunkTerms));
            if ($overlap >= 1) return true;
        }

        return false;
    }

    public function confidenceScore(array $evidence, string $query): float
    {
        if (empty($evidence)) return 0.0;

        $topScore   = $evidence[0]['score'] ?? 0.0;
        $queryTerms = $this->tokenize($query);
        $bestOverlap = 0;

        foreach ($evidence as $item) {
            $chunkTerms = $this->tokenize($item['chunk']->content);
            $overlap    = count(array_intersect($queryTerms, $chunkTerms));
            $bestOverlap = max($bestOverlap, $overlap);
        }

        $termBoost = min($bestOverlap / max(count($queryTerms), 1), 1.0);
        return min(($topScore + $termBoost) / 2, 1.0);
    }

    private function tokenize(string $text): array
    {
        // Same fix as QueryAgent::buildVector() — \W+ shatters Devanagari/Malayalam words at
        // every combining vowel sign; this keeps them intact across every script.
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($w) => strlen($w) >= 3);
        // Same stemming QueryAgent applies at retrieval time — without it, "service" (query)
        // vs "services" (knowledge base) share zero overlap here even when QueryAgent's own
        // cosine score already recognizes them as a match, and this overlap check would then
        // incorrectly veto an otherwise-good match.
        return array_unique(array_map([QueryAgent::class, 'stem'], $words));
    }
}
