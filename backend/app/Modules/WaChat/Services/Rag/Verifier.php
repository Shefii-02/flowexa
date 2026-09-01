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
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_unique(array_filter($words, fn($w) => strlen($w) >= 3));
    }
}
