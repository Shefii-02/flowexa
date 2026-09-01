<?php

namespace App\Services\MetaAI;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ConversationAnalysis;
use App\Models\LeadConversionEvent;
use App\Models\MetaAiConfig;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversationAnalyzer
{
    public function __construct(
        private readonly CompanyContextBuilder $contextBuilder,
        private readonly LeadScoreCalculator   $scoreCalculator,
    ) {}

    public function analyze(
        Company      $company,
        Contact      $contact,
        string       $phone,
        string       $latestMessage,
        array        $conversationHistory,
        MetaAiConfig $config
    ): ?ConversationAnalysis {
        $start = microtime(true);

        // Build context
        $context = $this->contextBuilder->build($company, $contact, $conversationHistory);

        // Resolve API key + model
        [$apiKey, $model, $provider] = $this->resolveApiKey($company, $config);

        if (!$apiKey) {
            Log::info("ConversationAnalyzer: no API key for company {$company->id}");
            return null;
        }

        // Call AI
        $raw = $this->callAI($apiKey, $model, $provider, $company->name, $context, $latestMessage);
        if (!$raw) return null;

        $parsed = $this->parseResponse($raw);
        if (!$parsed) return null;

        $ms = (int) ((microtime(true) - $start) * 1000);

        // Save analysis
        $analysis = ConversationAnalysis::create([
            'company_id'          => $company->id,
            'contact_id'          => $contact->id,
            'phone'               => $phone,
            'analyzed_at'         => now(),
            'sentiment'           => $parsed['sentiment']           ?? 'neutral',
            'sentiment_score'     => $parsed['sentiment_score']     ?? 0.5,
            'sentiment_reason'    => $parsed['sentiment_reason']    ?? null,
            'detected_intent'     => $parsed['detected_intent']     ?? 'browsing',
            'intent_confidence'   => $parsed['intent_confidence']   ?? 0,
            'intent_details'      => $parsed['intent_details']      ?? null,
            'lead_score'          => $parsed['lead_score']          ?? 0,
            'lead_score_reason'   => $parsed['lead_score_reason']   ?? null,
            'buying_signals'      => $parsed['buying_signals']      ?? [],
            'objections'          => $parsed['objections']          ?? [],
            'recommended_actions' => $parsed['recommended_actions'] ?? [],
            'suggested_response'  => $parsed['suggested_response']  ?? null,
            'escalate_to_human'   => $parsed['escalate_to_human']   ?? false,
            'escalation_reason'   => $parsed['escalation_reason']   ?? null,
            'model_used'          => $model,
            'analysis_ms'         => $ms,
        ]);

        // Auto-actions
        $this->runAutoActions($contact, $company, $config, $analysis, $latestMessage, $parsed);

        return $analysis;
    }

    private function resolveApiKey(Company $company, MetaAiConfig $config): array
    {
        // Try Meta AI key first
        if ($config->meta_ai_enabled && $config->meta_ai_api_key) {
            try {
                $key = decrypt($config->meta_ai_api_key);
                return [$key, $config->meta_ai_model, 'meta_ai'];
            } catch (\Exception) {}
        }

        // Fall back to company Anthropic key
        $anthropicKey = CompanyApiKeyResolver::anthropic($company);
        if ($anthropicKey) {
            $model = CompanyApiKeyResolver::model($company);
            return [$anthropicKey, $model, 'anthropic'];
        }

        // Fall back to company OpenAI key
        $openaiKey = CompanyApiKeyResolver::openai($company);
        if ($openaiKey) {
            return [$openaiKey, 'gpt-4o-mini', 'openai'];
        }

        return [null, null, null];
    }

    private function callAI(
        string $apiKey, string $model, string $provider,
        string $companyName, string $context, string $latestMessage
    ): ?string {
        $systemPrompt = "You are a sales conversation analyst for {$companyName}.
Analyze the latest customer message in context of the full conversation.

COMPANY CONTEXT:
{$context}

Analyze and return ONLY valid JSON (no markdown, no extra text):
{
  \"sentiment\": \"positive|neutral|negative|mixed\",
  \"sentiment_score\": 0.0-1.0,
  \"sentiment_reason\": \"brief explanation\",
  \"detected_intent\": \"browsing|price_inquiry|product_inquiry|complaint|buying_signal|ready_to_buy|needs_followup|not_interested|existing_customer|referral\",
  \"intent_confidence\": 0.0-1.0,
  \"intent_details\": \"what specifically they want\",
  \"lead_score\": 0-100,
  \"lead_score_reason\": \"why this score\",
  \"buying_signals\": [\"signal1\"],
  \"objections\": [\"objection1\"],
  \"recommended_actions\": [\"action1: description\"],
  \"suggested_response\": \"what the agent should say next\",
  \"escalate_to_human\": true|false,
  \"escalation_reason\": null,
  \"stage_recommendation\": \"new|engaged|interested|payment_sent|converted|lost|null\",
  \"stage_reason\": null
}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => "Latest customer message: \"{$latestMessage}\""],
        ];

        try {
            return match($provider) {
                'anthropic' => $this->callAnthropic($apiKey, $model, $systemPrompt, $latestMessage),
                'openai'    => $this->callOpenAI($apiKey, $model, $messages),
                'meta_ai'   => $this->callMetaOrTogether($apiKey, $model, $messages),
                default     => null,
            };
        } catch (\Exception $e) {
            Log::error("ConversationAnalyzer AI call failed: " . $e->getMessage());
            return null;
        }
    }

    private function callAnthropic(string $apiKey, string $model, string $system, string $userMsg): ?string
    {
        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 800,
            'temperature'=> 0.1,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => "Latest customer message: \"{$userMsg}\""]],
        ]);

        if ($response->successful()) return $response->json('content.0.text');
        Log::warning('ConversationAnalyzer Anthropic error: ' . $response->body());
        return null;
    }

    private function callOpenAI(string $apiKey, string $model, array $messages): ?string
    {
        $response = Http::withToken($apiKey)->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 800,
            'temperature' => 0.1,
        ]);

        if ($response->successful()) return $response->json('choices.0.message.content');
        Log::warning('ConversationAnalyzer OpenAI error: ' . $response->body());
        return null;
    }

    private function callMetaOrTogether(string $apiKey, string $model, array $messages): ?string
    {
        $result = MetaAiClient::chat($messages, $apiKey, $model, 800, 0.1);
        return $result['content'] ?? null;
    }

    private function parseResponse(string $raw): ?array
    {
        // Strip markdown code fences if present
        $clean = preg_replace('/^```json\s*/m', '', $raw);
        $clean = preg_replace('/^```\s*/m', '', $clean);

        $parsed = json_decode(trim($clean), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('ConversationAnalyzer: JSON parse failed: ' . substr($raw, 0, 300));
            return null;
        }
        return $parsed;
    }

    private function runAutoActions(
        Contact      $contact,
        Company      $company,
        MetaAiConfig $config,
        ConversationAnalysis $analysis,
        string       $latestMessage,
        array        $parsed
    ): void {
        $oldScore  = $contact->lead_score ?? 0;
        $newScore  = $parsed['lead_score'] ?? $oldScore;
        $intent    = $parsed['detected_intent'] ?? 'browsing';
        $signals   = $parsed['buying_signals'] ?? [];
        $stage     = $parsed['stage_recommendation'] ?? null;

        // Update contact intelligence fields
        $update = [
            'last_sentiment'   => $parsed['sentiment'] ?? null,
            'detected_intent'  => $intent,
            'lead_score'       => $newScore,
            'lead_score_updated_at' => now(),
        ];

        // Buying signals
        if ($config->detect_buying_signals && !empty($signals)) {
            $update['buying_signals_count'] = ($contact->buying_signals_count ?? 0) + count($signals);
            $this->logEvent($company, $contact, 'buying_signal_detected', null, implode(', ', $signals), $latestMessage, $analysis->id);
        }

        // Objections
        $objections = $parsed['objections'] ?? [];
        if (!empty($objections)) {
            $update['objections_count'] = ($contact->objections_count ?? 0) + count($objections);
            $this->logEvent($company, $contact, 'objection_detected', null, implode(', ', $objections), $latestMessage, $analysis->id);
        }

        // Lead score change event
        if (abs($newScore - $oldScore) >= 5) {
            $type = $newScore > $oldScore ? 'score_increased' : 'score_decreased';
            $this->logEvent($company, $contact, $type, (string)$oldScore, (string)$newScore, $latestMessage, $analysis->id);
        }

        // Auto-qualify
        if ($config->auto_qualify_leads && in_array($intent, ['buying_signal', 'ready_to_buy']) && $newScore >= 70) {
            $update['lead_stage'] = 'interested';
            $this->logEvent($company, $contact, 'auto_qualified', $contact->lead_stage, 'interested', $latestMessage, $analysis->id);
        }

        // Stage recommendation
        if ($stage && $stage !== 'null' && $stage !== ($contact->lead_stage ?? '')) {
            $update['lead_stage'] = $stage;
            $this->logEvent($company, $contact, 'stage_changed', $contact->lead_stage ?? 'new', $stage, $latestMessage, $analysis->id);
        }

        // Hand off to human
        if ($config->hand_off_threshold && ($parsed['intent_confidence'] ?? 0) >= $config->hand_off_threshold && ($parsed['escalate_to_human'] ?? false)) {
            $this->logEvent($company, $contact, 'handed_to_human', null, 'escalated', $latestMessage, $analysis->id);
        }

        $contact->update($update);
    }

    private function logEvent(Company $company, Contact $contact, string $type, ?string $from, ?string $to, string $trigger, int $analysisId): void
    {
        LeadConversionEvent::create([
            'company_id'      => $company->id,
            'contact_id'      => $contact->id,
            'phone'           => $contact->phone,
            'event_type'      => $type,
            'from_value'      => $from,
            'to_value'        => $to,
            'trigger_message' => substr($trigger, 0, 500),
            'analysis_id'     => $analysisId,
            'automated'       => true,
        ]);
    }
}
