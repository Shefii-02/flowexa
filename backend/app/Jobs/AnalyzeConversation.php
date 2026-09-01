<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Contact;
use App\Models\MetaAiConfig;
use App\Modules\WaChat\Models\AiAgentSession;
use App\Services\MetaAI\CompanyContextBuilder;
use App\Services\MetaAI\ConversationAnalyzer;
use App\Services\MetaAI\LeadScoreCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 2;
    public int    $timeout = 60;

    public function __construct(
        public readonly int    $companyId,
        public readonly int    $contactId,
        public readonly string $phone,
        public readonly string $latestMessage,
        public readonly string $sessionId = '',
    ) {}

    public function handle(): void
    {
        $company = Company::find($this->companyId);
        $contact = Contact::find($this->contactId);
        $config  = MetaAiConfig::where('company_id', $this->companyId)
            ->where('is_enabled', true)
            ->where('analyze_on_message', true)
            ->first();

        if (!$company || !$contact || !$config) return;

        // Fetch conversation history from AiAgentSession
        $session = AiAgentSession::where('company_id', $this->companyId)
            ->where('contact_phone', $this->phone)
            ->orderBy('last_message_at', 'desc')
            ->first();

        $history = $session ? array_slice($session->conversation_history ?? [], -$config->max_context_messages) : [];

        $analyzer = new ConversationAnalyzer(
            new CompanyContextBuilder(),
            new LeadScoreCalculator(),
        );

        $analysis = $analyzer->analyze($company, $contact, $this->phone, $this->latestMessage, $history, $config);

        if ($analysis) {
            Log::info("AnalyzeConversation: contact={$this->contactId} score={$analysis->lead_score} intent={$analysis->detected_intent}");

            // Schedule summary update if overdue
            $overdue = !$contact->summary_updated_at || $contact->summary_updated_at->diffInHours(now()) > 24;
            if ($overdue) {
                UpdateConversationSummary::dispatch($this->companyId, $this->contactId)->onQueue('analysis');
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("AnalyzeConversation failed for contact {$this->contactId}: " . $e->getMessage());
    }
}
