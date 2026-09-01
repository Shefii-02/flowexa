<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Modules\WaChat\Models\AiKnowledgeChunk;
use Illuminate\Support\Collection;

class QueryAgent
{
    private const TOP_K       = 5;
    private const MIN_SCORE   = 0.05;
    private const STOP_WORDS  = [
        'a','an','the','is','it','in','on','at','to','do','be','of',
        'and','or','but','for','with','this','that','are','was','were',
        'i','my','me','we','our','you','your','he','his','she','her',
        'they','their','what','how','can','will','please','help',
    ];

    /**
     * Retrieve top-K relevant chunks for a query using TF-IDF cosine similarity.
     *
     * @return array<array{chunk: AiKnowledgeChunk, score: float}>
     */
    public function retrieve(string $query, int $companyId): array
    {
        $queryVector = $this->buildVector($query);
        if (empty($queryVector)) return [];

        $chunks = AiKnowledgeChunk::where('company_id', $companyId)
            ->whereNotNull('tfidf_vector')
            ->get();

        $scored = $chunks->map(function (AiKnowledgeChunk $chunk) use ($queryVector) {
            $chunkVector = $chunk->tfidf_vector ?? [];
            $score       = $this->cosineSimilarity($queryVector, $chunkVector);
            return ['chunk' => $chunk, 'score' => $score];
        })
        ->filter(fn($item) => $item['score'] >= self::MIN_SCORE)
        ->sortByDesc('score')
        ->take(self::TOP_K)
        ->values()
        ->all();

        return $scored;
    }

    /**
     * Build a term-frequency map from text, excluding stop words.
     */
    public function buildVector(string $text): array
    {
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $tf    = [];

        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOP_WORDS)) continue;
            $tf[$word] = ($tf[$word] ?? 0) + 1;
        }

        // Normalize by total word count
        $total = array_sum($tf);
        if ($total > 0) {
            foreach ($tf as $term => $count) {
                $tf[$term] = $count / $total;
            }
        }

        return $tf;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $term => $val) {
            $dot += $val * ($b[$term] ?? 0.0);
        }

        $magA = sqrt(array_sum(array_map(fn($v) => $v * $v, $a)));
        $magB = sqrt(array_sum(array_map(fn($v) => $v * $v, $b)));

        if ($magA === 0.0 || $magB === 0.0) return 0.0;

        return $dot / ($magA * $magB);
    }
}
