<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Models\Company;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResponseGenerator
{
    private const MAX_TOKENS = 400;

    public function generate(
        string  $query,
        string  $context,
        string  $language,
        array   $conversationHistory,
        array   $aiConfig,
        Company $company
    ): string {
        // Resolve key: company DB key → .env fallback
        $apiKey = CompanyApiKeyResolver::anthropic($company);

        // Allow override from aiConfig for legacy test calls
        if (!empty($aiConfig['api_key'])) {
            $apiKey = $aiConfig['api_key'];
        }

        if (empty($apiKey)) {
            return "AI not configured. Please add an Anthropic API key in Settings → API Keys.";
        }

        $model        = CompanyApiKeyResolver::model($company);
        $systemPrompt = $this->buildSystemPrompt($context, $aiConfig, $language);
        $messages     = $this->buildMessages($conversationHistory, $query);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => self::MAX_TOKENS,
                'system'     => $systemPrompt,
                'messages'   => $messages,
            ]);

            if ($response->successful()) {
                // Record usage against the company's active key
                if ($company->anthropic_key_id && $company->anthropicKey) {
                    CompanyApiKeyResolver::recordUsage($company->anthropicKey, 0.0);
                }
                return $response->json('content.0.text') ?? $this->fallbackResponse($language);
            }

            Log::warning('ResponseGenerator Anthropic error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('ResponseGenerator exception: ' . $e->getMessage());
        }

        return $this->fallbackResponse($language);
    }

    private function buildSystemPrompt(string $context, array $aiConfig, string $language): string
    {
        $companyName  = $aiConfig['company_name']  ?? 'our company';
        $agentName    = $aiConfig['agent_name']    ?? 'AI Assistant';
        $customPrompt = $aiConfig['system_prompt'] ?? '';

        $base  = "You are {$agentName} for {$companyName}. ";
        $base .= "Answer only based on the provided knowledge base context. ";
        $base .= "Be concise and friendly. Reply in the same language as the user (detected: {$language}). ";
        $base .= "If the answer is not in the context, say you'll connect them with a human agent. ";
        $base .= "Keep responses under 150 words.\n\n";

        if ($customPrompt) {
            $base .= $customPrompt . "\n\n";
        }

        if (!empty($context)) {
            $base .= "KNOWLEDGE BASE CONTEXT:\n{$context}";
        }

        return $base;
    }

    private function buildMessages(array $history, string $currentQuery): array
    {
        $messages      = [];
        $recentHistory = array_slice($history, -8);

        foreach ($recentHistory as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $currentQuery];

        return $messages;
    }

    private function fallbackResponse(string $language): string
    {
        return match ($language) {
            'ar'    => 'عذراً، لم أتمكن من الإجابة. سيتواصل معك أحد ممثلينا قريباً.',
            'hi'    => 'क्षमा करें, मैं अभी उत्तर नहीं दे सकता। हमारा एजेंट जल्द आपसे संपर्क करेगा।',
            default => "I'm sorry, I couldn't find an answer to that. A human agent will assist you shortly.",
        };
    }
}
