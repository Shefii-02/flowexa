<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Models\Company;
use App\Modules\WaChat\Models\AiAgentSession;
use Illuminate\Support\Facades\Log;

class RagOrchestrator
{
    public function __construct(
        private readonly LanguageDetector  $languageDetector,
        private readonly PlannerAgent      $planner,
        private readonly EvidenceCollector $collector,
        private readonly Verifier          $verifier,
        private readonly ResponseGenerator $generator,
    ) {}

    public function answer(
        string $query,
        string $contactPhone,
        string $wahaSessionId,
        int    $companyId,
        array  $aiConfig = []
    ): array {
        // 1. Detect language
        $language = $this->languageDetector->detect($query);

        // 2. Get or create AI agent session
        $agentSession = AiAgentSession::firstOrCreate(
            [
                'company_id'      => $companyId,
                'waha_session_id' => $wahaSessionId,
                'contact_phone'   => $contactPhone,
                'status'          => 'active',
            ],
            [
                'conversation_history' => [],
                'ai_config'            => $aiConfig,
            ]
        );

        $history = $agentSession->conversation_history ?? [];

        // 3. Plan: decompose query into sub-queries
        $subQueries = $this->planner->decompose($query);
        $intent     = $this->planner->classifyIntent($query);

        // 4. Retrieve evidence
        $evidence = $this->collector->collect($subQueries, $companyId);
        $context  = $this->collector->buildContext($evidence);

        // 5. Verify relevance
        $isRelevant = $this->verifier->verify($evidence, $query);
        $confidence = $this->verifier->confidenceScore($evidence, $query);

        // Load Company model for key resolution (single query, cached by Eloquent)
        $company = Company::find($companyId) ?? new Company();

        // 6. Generate response
        if ($isRelevant) {
            $response = $this->generator->generate($query, $context, $language, $history, $aiConfig, $company);
            $status   = 'answered';
        } else {
            $response = $this->noAnswerResponse($language, $aiConfig);
            $status   = 'fallback';
        }

        // 7. Update conversation history
        $history[] = ['role' => 'user',      'content' => $query];
        $history[] = ['role' => 'assistant', 'content' => $response];

        // Keep last 20 turns
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $agentSession->update([
            'conversation_history' => $history,
            'current_intent'       => $intent,
            'last_message_at'      => now(),
            'ai_config'            => array_merge($agentSession->ai_config ?? [], $aiConfig),
        ]);

        return [
            'response'   => $response,
            'language'   => $language,
            'intent'     => $intent,
            'confidence' => round($confidence, 3),
            'status'     => $status,
            'evidence_count' => count($evidence),
        ];
    }

    private function noAnswerResponse(string $language, array $aiConfig): string
    {
        $transfer = $aiConfig['transfer_message'] ?? null;

        if ($transfer) return $transfer;

        return match($language) {
            'ar' => 'شكراً لتواصلك. لم أجد إجابة مناسبة، سيتواصل معك أحد ممثلينا قريباً.',
            'hi' => 'धन्यवाद! मुझे इसका उत्तर नहीं मिला। हमारा एजेंट जल्द आपसे जुड़ेगा।',
            default => "Thank you for reaching out! I couldn't find a specific answer to your question. A team member will follow up with you shortly.",
        };
    }
}
