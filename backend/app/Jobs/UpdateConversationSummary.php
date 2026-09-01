<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Contact;
use App\Modules\WaChat\Models\AiAgentSession;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateConversationSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 45;

    public function __construct(
        public readonly int $companyId,
        public readonly int $contactId,
    ) {}

    public function handle(): void
    {
        $company = Company::find($this->companyId);
        $contact = Contact::find($this->contactId);
        if (!$company || !$contact) return;

        $session = AiAgentSession::where('company_id', $this->companyId)
            ->where('contact_phone', $contact->phone)
            ->orderBy('last_message_at', 'desc')
            ->first();

        $history = $session?->conversation_history ?? [];
        if (empty($history)) return;

        $apiKey = CompanyApiKeyResolver::anthropic($company);
        if (!$apiKey) {
            $apiKey = CompanyApiKeyResolver::openai($company);
            if (!$apiKey) return;
        }

        $historyText = implode("\n", array_map(
            fn($t) => ($t['role'] === 'user' ? 'Customer' : 'Agent') . ': ' . ($t['content'] ?? ''),
            array_slice($history, -20)
        ));

        $summary = $this->generateSummary($apiKey, $historyText, $company);
        if ($summary) {
            $contact->update([
                'conversation_summary' => $summary,
                'summary_updated_at'   => now(),
            ]);
        }
    }

    private function generateSummary(string $apiKey, string $historyText, Company $company): ?string
    {
        $systemPrompt = "Summarize this sales conversation in 2-3 sentences.
Focus on: what customer wants, their budget, objections, and current status.
Be concise and factual.";

        try {
            // Try Anthropic first
            $anthropicKey = CompanyApiKeyResolver::anthropic($company);
            if ($anthropicKey) {
                $model    = CompanyApiKeyResolver::model($company);
                $response = Http::withHeaders([
                    'x-api-key'         => $anthropicKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 200,
                    'system'     => $systemPrompt,
                    'messages'   => [['role' => 'user', 'content' => $historyText]],
                ]);
                if ($response->successful()) return $response->json('content.0.text');
            }

            // Try OpenAI
            $openaiKey = CompanyApiKeyResolver::openai($company);
            if ($openaiKey) {
                $response = Http::withToken($openaiKey)->timeout(20)->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => 'gpt-4o-mini',
                    'max_tokens'  => 200,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $historyText],
                    ],
                ]);
                if ($response->successful()) return $response->json('choices.0.message.content');
            }
        } catch (\Exception $e) {
            Log::error("UpdateConversationSummary failed: " . $e->getMessage());
        }
        return null;
    }

    public function failed(\Throwable $e): void
    {
        Log::error("UpdateConversationSummary failed for contact {$this->contactId}: " . $e->getMessage());
    }
}
