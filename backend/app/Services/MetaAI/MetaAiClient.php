<?php

namespace App\Services\MetaAI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAiClient
{
    /**
     * Send messages to an LLM. Falls back through providers:
     *  1. Meta AI direct API (if meta_ai_api_key set)
     *  2. Together AI (hosts Llama models)
     *  3. Anthropic Claude (via existing company key)
     */
    public static function chat(
        array  $messages,
        string $apiKey,
        string $model = 'meta-llama/Llama-3.1-8B-Instruct',
        int    $maxTokens = 800,
        float  $temperature = 0.1
    ): array {
        // Try Together AI if model looks like Llama
        if (str_contains($model, 'llama') || str_contains($model, 'Llama')) {
            $result = self::callTogetherAI($messages, $apiKey, $model, $maxTokens, $temperature);
            if ($result !== null) return $result;
        }

        // Try Meta AI direct
        $result = self::callMetaAI($messages, $apiKey, $model, $maxTokens, $temperature);
        if ($result !== null) return $result;

        return ['content' => null, 'error' => 'All providers failed'];
    }

    private static function callTogetherAI(
        array $messages, string $apiKey, string $model,
        int $maxTokens, float $temperature
    ): ?array {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.together.xyz/v1/chat/completions', [
                    'model'       => $model,
                    'messages'    => $messages,
                    'max_tokens'  => $maxTokens,
                    'temperature' => $temperature,
                ]);

            if ($response->successful()) {
                return [
                    'content'      => $response->json('choices.0.message.content'),
                    'tokens_used'  => $response->json('usage.total_tokens', 0),
                    'provider'     => 'together_ai',
                ];
            }
        } catch (\Exception $e) {
            Log::debug('MetaAiClient::callTogetherAI failed: ' . $e->getMessage());
        }
        return null;
    }

    private static function callMetaAI(
        array $messages, string $apiKey, string $model,
        int $maxTokens, float $temperature
    ): ?array {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.meta.ai/v1/chat/completions', [
                    'model'       => $model,
                    'messages'    => $messages,
                    'max_tokens'  => $maxTokens,
                    'temperature' => $temperature,
                ]);

            if ($response->successful()) {
                return [
                    'content'      => $response->json('choices.0.message.content'),
                    'tokens_used'  => $response->json('usage.total_tokens', 0),
                    'provider'     => 'meta_ai',
                ];
            }
        } catch (\Exception $e) {
            Log::debug('MetaAiClient::callMetaAI failed: ' . $e->getMessage());
        }
        return null;
    }

    public static function availableModels(): array
    {
        return [
            ['id' => 'meta-llama/Llama-3.1-8B-Instruct',        'label' => 'Llama 3.1 8B',   'cost' => '$',  'description' => 'Fast, cheap analysis'],
            ['id' => 'meta-llama/Llama-3.1-70B-Instruct',       'label' => 'Llama 3.1 70B',  'cost' => '$$', 'description' => 'Better accuracy'],
            ['id' => 'meta-llama/Llama-3.1-8B-Instruct-Turbo',  'label' => 'Llama 3.1 8B Turbo', 'cost' => '$', 'description' => 'Fastest via Together AI'],
            ['id' => 'meta-llama/Llama-3.1-70B-Instruct-Turbo', 'label' => 'Llama 3.1 70B Turbo', 'cost' => '$$', 'description' => 'Best quality via Together AI'],
        ];
    }
}
