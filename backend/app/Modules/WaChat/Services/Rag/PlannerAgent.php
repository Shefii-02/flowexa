<?php

namespace App\Modules\WaChat\Services\Rag;

class PlannerAgent
{
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

        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn($w) =>
            strlen($w) >= 3 && !in_array($w, $stopWords)
        ));
    }
}
