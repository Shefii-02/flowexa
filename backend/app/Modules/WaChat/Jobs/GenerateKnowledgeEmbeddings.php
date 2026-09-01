<?php

namespace App\Modules\WaChat\Jobs;

use App\Modules\WaChat\Models\AiKnowledgeBase;
use App\Modules\WaChat\Models\AiKnowledgeChunk;
use App\Modules\WaChat\Services\Rag\QueryAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class GenerateKnowledgeEmbeddings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 2;
    public int $timeout = 300;

    private const CHUNK_SIZE = 400;
    private const CHUNK_OVERLAP = 50;

    public function __construct(public readonly int $knowledgeBaseId) {}

    public function handle(QueryAgent $queryAgent): void
    {
        $kb = AiKnowledgeBase::find($this->knowledgeBaseId);
        if (!$kb) return;

        $kb->update(['status' => 'processing']);

        try {
            $content = $this->resolveContent($kb);
            if (empty(trim($content))) {
                $kb->update(['status' => 'failed', 'error_message' => 'No content found.']);
                return;
            }

            // Delete existing chunks
            AiKnowledgeChunk::where('knowledge_base_id', $kb->id)->delete();

            $chunks = $this->splitIntoChunks($content);
            $count  = 0;

            foreach ($chunks as $index => $chunk) {
                $vector = $queryAgent->buildVector($chunk);

                AiKnowledgeChunk::create([
                    'knowledge_base_id' => $kb->id,
                    'company_id'        => $kb->company_id,
                    'content'           => $chunk,
                    'chunk_index'       => $index,
                    'tfidf_vector'      => $vector,
                ]);

                $count++;
            }

            $wordCount = str_word_count($content);
            $kb->update([
                'status'      => 'ready',
                'word_count'  => $wordCount,
                'chunk_count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::error("GenerateKnowledgeEmbeddings #{$kb->id}: " . $e->getMessage());
            $kb->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    private function resolveContent(AiKnowledgeBase $kb): string
    {
        if ($kb->document_type === 'text') {
            return $kb->raw_content ?? '';
        }

        if ($kb->document_type === 'file' && $kb->file_path) {
            $fullPath = storage_path('app/public/' . $kb->file_path);
            return file_exists($fullPath) ? file_get_contents($fullPath) : '';
        }

        if ($kb->document_type === 'url' && $kb->source_url) {
            $response = \Illuminate\Support\Facades\Http::timeout(20)->get($kb->source_url);
            if ($response->successful()) {
                // Strip HTML tags
                return strip_tags($response->body());
            }
        }

        return '';
    }

    private function splitIntoChunks(string $text): array
    {
        $words  = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $total  = count($words);

        for ($start = 0; $start < $total; $start += (self::CHUNK_SIZE - self::CHUNK_OVERLAP)) {
            $slice    = array_slice($words, $start, self::CHUNK_SIZE);
            $chunks[] = implode(' ', $slice);

            if ($start + self::CHUNK_SIZE >= $total) break;
        }

        return array_filter($chunks, fn($c) => strlen(trim($c)) > 20);
    }
}
