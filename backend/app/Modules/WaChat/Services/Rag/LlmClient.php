<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Models\Company;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-neutral chat completion. Resolves the company's active provider
 * (company key → platform key → env) and routes to Anthropic / OpenAI / Gemini.
 */
class LlmClient
{
    /**
     * @param array<array{role:string,content:string}> $messages
     * @return string|null  the assistant text, or null on any failure
     */
    public function chat(Company $company, string $system, array $messages, int $maxTokens = 400): ?string
    {
        $resolved = CompanyApiKeyResolver::resolve($company);
        if (!$resolved) {
            return null;
        }

        try {
            $text = match ($resolved['provider']) {
                'openai'    => $this->openai($resolved['key'], $resolved['model'], $system, $messages, $maxTokens),
                'google_ai' => $this->google($resolved['key'], $resolved['model'], $system, $messages, $maxTokens),
                default     => $this->anthropic($resolved['key'], $resolved['model'], $system, $messages, $maxTokens),
            };

            if ($text !== null && $resolved['key_model']) {
                CompanyApiKeyResolver::recordUsage($resolved['key_model'], 0.0);
            }

            return $text;
        } catch (\Throwable $e) {
            Log::error("LlmClient ({$resolved['provider']}) exception: " . $e->getMessage());
            return null;
        }
    }

    /** True when the company has any usable key for its active provider. */
    public function isConfigured(Company $company): bool
    {
        return CompanyApiKeyResolver::resolve($company) !== null;
    }

    private function anthropic(string $key, string $model, string $system, array $messages, int $maxTokens): ?string
    {
        $res = Http::withHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => $messages,
        ]);

        if ($res->successful()) {
            return $res->json('content.0.text');
        }

        Log::warning('LlmClient Anthropic error: ' . $res->body());
        return null;
    }

    private function openai(string $key, string $model, string $system, array $messages, int $maxTokens): ?string
    {
        $res = Http::withToken($key)->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => array_merge([['role' => 'system', 'content' => $system]], $messages),
        ]);

        if ($res->successful()) {
            return $res->json('choices.0.message.content');
        }

        Log::warning('LlmClient OpenAI error: ' . $res->body());
        return null;
    }

    private function google(string $key, string $model, string $system, array $messages, int $maxTokens): ?string
    {
        $contents = array_map(fn($m) => [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $res = Http::timeout(30)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents'          => $contents,
                'generationConfig'  => ['maxOutputTokens' => $maxTokens],
            ]
        );

        if ($res->successful()) {
            return $res->json('candidates.0.content.parts.0.text');
        }

        Log::warning('LlmClient Google AI error: ' . $res->body());
        return null;
    }
}
