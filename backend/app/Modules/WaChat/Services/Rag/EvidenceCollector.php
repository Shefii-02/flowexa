<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Modules\WaChat\Models\AiKnowledgeChunk;

class EvidenceCollector
{
    public function __construct(private readonly QueryAgent $queryAgent) {}

    /**
     * Collect evidence across multiple sub-queries, deduplicate by chunk_id.
     *
     * @return array<array{chunk: AiKnowledgeChunk, score: float}>
     */
    public function collect(array $subQueries, int $companyId): array
    {
        $seen   = [];
        $merged = [];

        foreach ($subQueries as $q) {
            $results = $this->queryAgent->retrieve($q, $companyId);
            foreach ($results as $item) {
                $id = $item['chunk']->id;
                if (isset($seen[$id])) {
                    // Boost score for chunks retrieved by multiple queries
                    $seen[$id]['score'] = max($seen[$id]['score'], $item['score']) * 1.1;
                } else {
                    $seen[$id] = $item;
                    $merged[]  = &$seen[$id];
                }
            }
        }

        usort($merged, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($merged, 0, 5);
    }

    public function buildContext(array $evidence): string
    {
        if (empty($evidence)) return '';

        $parts = [];
        foreach ($evidence as $item) {
            $parts[] = trim($item['chunk']->content);
        }

        return implode("\n\n---\n\n", $parts);
    }
}
