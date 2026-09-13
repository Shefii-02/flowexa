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
        // Legacy test calls may pass an explicit key/provider in aiConfig.
        if (!empty($aiConfig['api_key'])) {
            $provider = $aiConfig['provider'] ?? 'anthropic';
            $apiKey   = $aiConfig['api_key'];
            $model    = $aiConfig['model'] ?? CompanyApiKeyResolver::model($company);
            $keyModel = null;
        } elseif (!empty($aiConfig['key_id'])) {
            // Test tool picked one specific stored key row — a company can hold several keys
            // for the same provider (e.g. two google_ai keys for two projects) with only one
            // marked active, so "pick a provider" alone isn't precise enough to test a
            // non-active key. This resolves that exact row regardless of its active flag.
            $resolved = CompanyApiKeyResolver::resolveByKeyId($company, (int) $aiConfig['key_id']);
            if (!$resolved) {
                return "That API key could not be found — it may have been deleted or belongs to a different company.";
            }
            $provider = $resolved['provider'];
            $apiKey   = $resolved['key'];
            $model    = $aiConfig['model'] ?? $resolved['model'];
            $keyModel = $resolved['key_model'];
        } elseif (!empty($aiConfig['provider'])) {
            // Test tool asked to try a *specific* provider using the company's own stored
            // key for it — not a pasted key (that's the branch above), and not necessarily
            // the company's one globally-active provider. Previously this case fell through
            // to CompanyApiKeyResolver::resolve() below, which always returns the active
            // provider regardless — so picking a different provider in the test tool had no
            // effect at all. Model comes from whatever was chosen for that provider under
            // Settings; there's no separate model picker here on purpose.
            $resolved = CompanyApiKeyResolver::resolveForProvider($company, $aiConfig['provider']);
            if (!$resolved) {
                return "No {$aiConfig['provider']} key is configured for this company yet. Add one under WA Agent → Settings → API Keys.";
            }
            $provider = $resolved['provider'];
            $apiKey   = $resolved['key'];
            $model    = $aiConfig['model'] ?? $resolved['model'];
            $keyModel = $resolved['key_model'];
        } else {
            $resolved = CompanyApiKeyResolver::resolve($company);
            if (!$resolved) {
                return "AI is not configured yet. Add an API key under WA Agent → Settings.";
            }
            $provider = $resolved['provider'];
            $apiKey   = $resolved['key'];
            $model    = $resolved['model'];
            $keyModel = $resolved['key_model'];
        }

        $systemPrompt = $this->buildSystemPrompt($context, $aiConfig, $language);
        $messages     = $this->buildMessages($conversationHistory, $query);

        try {
            $text = match ($provider) {
                'openai'    => $this->callOpenAI($apiKey, $model, $systemPrompt, $messages),
                'google_ai' => $this->callGoogle($apiKey, $model, $systemPrompt, $messages),
                default     => $this->callAnthropic($apiKey, $model, $systemPrompt, $messages),
            };

            if ($text !== null) {
                if ($keyModel) {
                    CompanyApiKeyResolver::recordUsage($keyModel, 0.0);
                }
                return $text;
            }
        } catch (\Exception $e) {
            Log::error("ResponseGenerator ({$provider}) exception: " . $e->getMessage());
        }

        return $this->fallbackResponse($language);
    }

    // ── Provider callers ─────────────────────────────────────────────────────

    private function callAnthropic(string $apiKey, string $model, string $system, array $messages): ?string
    {
        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system,
            'messages'   => $messages,
        ]);

        if ($response->successful()) {
            return $response->json('content.0.text');
        }

        Log::warning('ResponseGenerator Anthropic error: ' . $response->body());
        return null;
    }

    private function callOpenAI(string $apiKey, string $model, string $system, array $messages): ?string
    {
        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'      => $model,
                'max_tokens' => self::MAX_TOKENS,
                'messages'   => array_merge([['role' => 'system', 'content' => $system]], $messages),
            ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        Log::warning('ResponseGenerator OpenAI error: ' . $response->body());
        return null;
    }

    private function callGoogle(string $apiKey, string $model, string $system, array $messages): ?string
    {
        // Interactions API (POST /v1beta/interactions, x-goog-api-key header, {model, input})
        // — the old /v1beta/models/{model}:generateContent + ?key= shape is what returned
        // "model not found for generateContent" for current Gemini models (confirmed live).
        // A structured multi-turn `input` array isn't confirmed for this endpoint, so the
        // conversation is folded into one plain-text transcript instead — guaranteed to
        // actually reach the model rather than risking an unconfirmed field being ignored.
        $transcript = collect($messages)
            ->map(fn ($m) => ($m['role'] === 'assistant' ? 'Assistant' : 'User') . ': ' . $m['content'])
            ->implode("\n");

        $input = "{$system}\n\nConversation so far:\n{$transcript}\n\nAssistant:";

        return \App\Services\GoogleAiModelService::generate($apiKey, $model, $input);
    }

    // ── Prompt building ──────────────────────────────────────────────────────

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
