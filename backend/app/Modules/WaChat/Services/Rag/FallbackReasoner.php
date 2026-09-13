<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Secondary reasoning step — runs only when the primary RAG pipeline (retrieval + relevance
 * check) finds nothing relevant enough to answer from the knowledge base. Rather than
 * immediately giving up with a static clarifying-question template, this makes one more LLM
 * call that can also draw on the customer's own stored CRM data (contact profile, lead notes)
 * and general knowledge, and either answers directly or asks a clarifying question tailored
 * to what was actually unclear — the "Agent Reasoning" / "Search Stored Customer Data" steps
 * of the agent architecture, added as a fallback on top of the already-working RAG path
 * rather than replacing it.
 *
 * If this call itself fails (no AI key, API error, unparseable response), reason() returns
 * null and the caller falls back to the existing static template — this is a "try harder"
 * step, not a replacement for that safety net.
 */
class FallbackReasoner
{
    public function __construct(private readonly LlmClient $llm) {}

    /** @return array{can_answer:bool, reply:string}|null null = the reasoning call itself failed */
    public function reason(
        Company $company,
        ?Contact $contact,
        string  $query,
        array   $history,
        string  $languageLabel
    ): ?array {
        $system   = $this->buildSystemPrompt($company, $this->buildCrmContext($contact), $languageLabel);
        $messages = $this->buildMessages($history, $query);

        $raw = $this->llm->chat($company, $system, $messages, 400);
        if ($raw === null) {
            return null;
        }

        $parsed = $this->parseJson($raw);
        if (!$parsed || !isset($parsed['reply']) || $parsed['reply'] === '') {
            return null;
        }

        return [
            'can_answer' => (bool) ($parsed['can_answer'] ?? false),
            'reply'      => trim((string) $parsed['reply']),
        ];
    }

    /** The "Search Stored Customer / CRM Data" step — a compact text summary, not raw rows. */
    private function buildCrmContext(?Contact $contact): string
    {
        if (!$contact) {
            return 'No stored information about this customer yet — this looks like a new contact.';
        }

        $parts = [];
        if ($contact->name && $contact->name !== $contact->phone) {
            $parts[] = "Name: {$contact->name}";
        }
        if ($contact->lead_stage) {
            $parts[] = "Lead stage: {$contact->lead_stage}";
        }
        if ($contact->detected_intent) {
            $parts[] = "Previously detected intent: {$contact->detected_intent}";
        }
        if ($contact->conversation_summary) {
            $parts[] = "Conversation summary so far: {$contact->conversation_summary}";
        }
        if (!empty($contact->custom_fields)) {
            $fields = collect($contact->custom_fields)->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ');
            if ($fields !== '') {
                $parts[] = "Custom fields: {$fields}";
            }
        }

        $lead = Lead::where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->whereNotIn('stage', ['lost', 'disqualified'])
            ->latest()
            ->first();
        if ($lead && $lead->notes) {
            $parts[] = 'Notes on file: ' . mb_substr($lead->notes, 0, 300);
        }

        return $parts ? implode("\n", $parts) : 'No stored information about this customer yet.';
    }

    private function buildSystemPrompt(Company $company, string $crmContext, string $languageLabel): string
    {
        $companyName = $company->name ?: 'our company';

        return <<<TXT
You are a WhatsApp assistant for {$companyName}. The customer just asked something the
company's knowledge base could not directly answer.

WHAT WE KNOW ABOUT THIS CUSTOMER (from CRM):
{$crmContext}

Decide ONE of two things:
1. If you can reasonably and honestly answer using general knowledge, or using the customer
   information above, do so — concisely, in your own natural words, and never invent
   company-specific facts (prices, policies, features) you don't actually have.
2. If you genuinely cannot answer without more detail from the customer, ask ONE short,
   natural clarifying question about what they actually need.

Reply in {$languageLabel}.

Respond with ONLY this JSON (no markdown fences, no extra text):
{"can_answer": true or false, "reply": "your answer or clarifying question"}
TXT;
    }

    private function buildMessages(array $history, string $query): array
    {
        $messages = [];
        foreach (array_slice($history, -8) as $turn) {
            $role       = ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($turn['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $query];

        return $messages;
    }

    private function parseJson(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $clean);
        $clean = preg_replace('/\s*```$/m', '', $clean);

        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $clean = $m[0];
        }

        $decoded = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('FallbackReasoner: JSON parse failed: ' . mb_substr($raw, 0, 300));
            return null;
        }

        return $decoded;
    }
}
