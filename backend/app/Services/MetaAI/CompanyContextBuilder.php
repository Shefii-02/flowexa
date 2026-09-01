<?php

namespace App\Services\MetaAI;

use App\Models\Company;
use App\Models\Contact;
use App\Modules\WaChat\Models\AiAgentSession;
use App\Modules\WaChat\Models\AiKnowledgeBase;
use Carbon\Carbon;

class CompanyContextBuilder
{
    private const MAX_TOKENS = 4000;

    public function build(Company $company, Contact $contact, array $conversationHistory = []): string
    {
        $parts = [];

        // 1. Company profile
        $parts[] = $this->buildCompanyProfile($company);

        // 2. Services & products from knowledge base
        $parts[] = $this->buildServicesSection($company);

        // 3. Customer profile
        $parts[] = $this->buildCustomerProfile($contact);

        // 4. Conversation history
        if (!empty($conversationHistory)) {
            $parts[] = $this->buildConversationHistory($conversationHistory);
        }

        // 5. Current context
        $parts[] = $this->buildCurrentContext();

        $context = implode("\n\n", array_filter($parts));

        // Truncate if too long (rough token estimate: 1 token ≈ 4 chars)
        if (strlen($context) > self::MAX_TOKENS * 4) {
            $context = substr($context, 0, self::MAX_TOKENS * 4) . "\n[Context truncated]";
        }

        return $context;
    }

    public function buildSummary(Company $company, Contact $contact): string
    {
        $parts = [
            $this->buildCompanyProfile($company),
            $this->buildCustomerProfile($contact),
            $this->buildCurrentContext(),
        ];
        $context = implode("\n\n", array_filter($parts));

        // 500-token limit for quick classification
        if (strlen($context) > 500 * 4) {
            $context = substr($context, 0, 500 * 4);
        }

        return $context;
    }

    private function buildCompanyProfile(Company $company): string
    {
        $settings = $company->settings ?? [];
        $lines    = ["=== COMPANY PROFILE ==="];
        $lines[]  = "Company: {$company->name}";

        if (!empty($settings['industry']))   $lines[] = "Industry: {$settings['industry']}";
        if (!empty($settings['location']))   $lines[] = "Location: {$settings['location']}";
        if (!empty($settings['description'])) $lines[] = "About: {$settings['description']}";
        if ($company->email)                 $lines[] = "Email: {$company->email}";
        if ($company->phone)                 $lines[] = "Phone: {$company->phone}";
        if ($company->website)               $lines[] = "Website: {$company->website}";

        return implode("\n", $lines);
    }

    private function buildServicesSection(Company $company): string
    {
        $items = AiKnowledgeBase::where('company_id', $company->id)
            ->whereIn('document_type', ['product', 'service', 'pricing', 'text'])
            ->where('status', 'ready')
            ->orderBy('name')
            ->take(10)
            ->get();

        if ($items->isEmpty()) return '';

        $lines = ["=== SERVICES & PRODUCTS ==="];
        foreach ($items as $item) {
            $line = "• {$item->name}";
            if ($item->raw_content) {
                $snippet = substr(strip_tags($item->raw_content), 0, 200);
                $line   .= ": {$snippet}";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function buildCustomerProfile(Contact $contact): string
    {
        $lines   = ["=== CUSTOMER PROFILE ==="];
        $lines[] = "Name: " . ($contact->name ?: 'Unknown');
        $lines[] = "Phone: {$contact->phone}";

        if ($contact->lead_stage)    $lines[] = "Lead Stage: {$contact->lead_stage}";
        if ($contact->lead_score)    $lines[] = "Lead Score: {$contact->lead_score}/100";
        if ($contact->last_sentiment) $lines[] = "Last Sentiment: {$contact->last_sentiment}";
        if ($contact->detected_intent) $lines[] = "Detected Intent: {$contact->detected_intent}";
        if ($contact->buying_signals_count > 0) $lines[] = "Buying Signals: {$contact->buying_signals_count}";
        if ($contact->objections_count > 0)     $lines[] = "Objections: {$contact->objections_count}";

        if ($contact->conversation_summary) {
            $lines[] = "Conversation Summary: {$contact->conversation_summary}";
        }

        if ($contact->created_at) {
            $lines[] = "First Contact: " . $contact->created_at->format('d M Y');
        }

        return implode("\n", $lines);
    }

    private function buildConversationHistory(array $history): string
    {
        if (empty($history)) return '';

        $lines = ["=== RECENT CONVERSATION ==="];
        foreach ($history as $turn) {
            $role = $turn['role'] === 'user' ? 'Customer' : 'Agent';
            $lines[] = "{$role}: " . substr($turn['content'] ?? '', 0, 300);
        }

        return implode("\n", $lines);
    }

    private function buildCurrentContext(): string
    {
        $now  = Carbon::now('Asia/Kolkata');
        $lines = ["=== CURRENT CONTEXT ==="];
        $lines[] = "Date/Time (IST): " . $now->format('l, d M Y H:i');
        $lines[] = "Day: " . $now->format('l');

        $hour        = (int) $now->format('H');
        $bizHours    = ($hour >= 9 && $hour < 19);
        $lines[]     = "Business Hours: " . ($bizHours ? 'Yes' : 'No');

        return implode("\n", $lines);
    }
}
