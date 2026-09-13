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
        // [^\p{L}\p{M}\p{N}]+ (not \W+) — \W excludes combining marks (Unicode category Mn/Mc),
        // which shatters Devanagari/Malayalam/etc. words at every vowel sign into meaningless
        // single-consonant fragments ("कीमत" -> "क","मत"). Those fragments are common enough
        // across unrelated text that a totally off-topic query in that script could spuriously
        // "match" any other content in the same script. Keeping marks attached to their base
        // letter keeps real words intact for every script, and is a no-op for scripts (Latin,
        // Arabic, CJK, digits) that don't use marks this way.
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $tf    = [];

        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOP_WORDS)) continue;
            $word = self::stem($word);
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

    /**
     * Reduces a word to a rough common stem so "service"/"services", "product"/"products",
     * "price"/"prices" etc. count as the same term. This is not linguistically precise (it
     * won't handle irregular plurals, and it can produce a non-word stem like "busines" for
     * "business") — that's fine here, since TF-IDF only needs the SAME real-world word to map
     * to the SAME token consistently, not a dictionary-correct root. Applied identically to
     * both knowledge base content (at indexing time) and the live query (at search time), so
     * whichever form a customer happens to type, it lines up with whichever form the
     * knowledge base happens to use. Without this, a customer saying "I need your service"
     * against content that only ever says "services" shares zero literal words and always
     * fails to match, despite being exactly the question the knowledge base answers.
     */
    public static function stem(string $word): string
    {
        $len = strlen($word);

        if ($len > 4 && str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y'; // companies -> company
        }

        if ($len > 4 && preg_match('/(?:s|x|z|ch|sh)es$/', $word)) {
            return substr($word, 0, -2); // boxes -> box, matches -> match
        }

        if ($len > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1); // services -> service, products -> product
        }

        return $word;
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
