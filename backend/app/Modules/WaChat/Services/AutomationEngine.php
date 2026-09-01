<?php

namespace App\Modules\WaChat\Services;

use App\Jobs\AnalyzeConversation;
use App\Models\Contact;
use App\Models\MetaAiConfig;
use App\Modules\WaChat\Models\AutomationRule;
use App\Modules\WaChat\Models\AutomationLog;
use App\Modules\WaChat\Models\FollowUpQueue;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Jobs\SendAutomationMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AutomationEngine
{
    /**
     * Called from the Node.js webhook bridge when a new message arrives.
     * Evaluates all active rules for the session/company and dispatches actions.
     */
    public function handleIncomingMessage(array $event): void
    {
        $sessionId = $event['session'] ?? null;
        $phone     = $event['from']    ?? null;
        $text      = $event['body']    ?? '';
        $isGroup   = str_ends_with((string)$phone, '@g.us');

        if (!$sessionId || !$phone || $isGroup) return;

        $session = WahaSession::where('session_id', $sessionId)->first();
        if (!$session) return;

        $companyId = $session->company_id;
        $phone     = preg_replace('/@.*/', '', $phone);

        // ── Dispatch conversation analysis (non-blocking background job) ──────
        $metaAiConfig = MetaAiConfig::where('company_id', $companyId)
            ->where('is_enabled', true)
            ->where('analyze_on_message', true)
            ->first();

        if ($metaAiConfig) {
            $contact = Contact::where('company_id', $companyId)->where('phone', $phone)->first();
            if ($contact) {
                AnalyzeConversation::dispatch(
                    $companyId, $contact->id, $phone, $text, $sessionId
                )->onQueue('analysis');
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $rules = AutomationRule::where('company_id', $companyId)
            ->where('session_id', $sessionId)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($rules as $rule) {
            $this->evaluateRule($rule, $companyId, $sessionId, $phone, $text, $event);
        }
    }

    private function evaluateRule(
        AutomationRule $rule,
        int $companyId,
        string $sessionId,
        string $phone,
        string $text,
        array $event
    ): void {
        try {
            $matched = match($rule->rule_type) {
                'welcome_message'    => $this->evalWelcome($rule, $phone, $companyId),
                'out_of_office'      => $this->evalOutOfOffice($rule),
                'lead_qualifier'     => $this->evalLeadQualifier($rule, $text),
                'keyword_trigger'    => $this->evalKeyword($rule, $text),
                'inactivity_trigger' => false, // handled by cron
                'follow_up_reminder' => false, // handled by cron
                'follow_up_agent'    => false, // handled by cron
                default              => false,
            };

            if (!$matched) return;

            $this->executeActions($rule, $companyId, $sessionId, $phone, $text);
        } catch (\Exception $e) {
            Log::error("AutomationEngine rule #{$rule->id} error: " . $e->getMessage());
            AutomationLog::create([
                'company_id'    => $companyId,
                'rule_id'       => $rule->id,
                'session_id'    => $sessionId,
                'contact_phone' => $phone,
                'rule_type'     => $rule->rule_type,
                'trigger_data'  => ['text' => $text],
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function evalWelcome(AutomationRule $rule, string $phone, int $companyId): bool
    {
        // Fire only once per contact (no previous automation log for welcome)
        return !AutomationLog::where('rule_id', $rule->id)
            ->where('contact_phone', $phone)
            ->where('status', 'success')
            ->exists();
    }

    private function evalOutOfOffice(AutomationRule $rule): bool
    {
        if (!$rule->schedule_start || !$rule->schedule_end) return false;

        $now   = Carbon::now();
        $start = Carbon::createFromTimeString($rule->schedule_start);
        $end   = Carbon::createFromTimeString($rule->schedule_end);

        // Check day of week
        if (!empty($rule->schedule_days)) {
            $dayName = strtolower($now->format('l'));
            if (!in_array($dayName, $rule->schedule_days)) return true; // outside working days = OOO
        }

        // If end < start, overnight range
        if ($end->lt($start)) {
            return $now->gte($start) || $now->lte($end);
        }

        return !($now->gte($start) && $now->lte($end));
    }

    private function evalLeadQualifier(AutomationRule $rule, string $text): bool
    {
        $conditions = $rule->conditions ?? [];
        if (empty($conditions['qualifier_keywords'])) return true;

        $keywords = (array) $conditions['qualifier_keywords'];
        $lower    = mb_strtolower($text);

        foreach ($keywords as $kw) {
            if (str_contains($lower, mb_strtolower($kw))) return true;
        }

        return false;
    }

    private function evalKeyword(AutomationRule $rule, string $text): bool
    {
        $keywords = $rule->keywords ?? [];
        if (empty($keywords)) return false;

        $lower = mb_strtolower(trim($text));

        foreach ($keywords as $kw) {
            if (mb_strtolower(trim($kw)) === $lower) return true;
        }

        return false;
    }

    private function executeActions(
        AutomationRule $rule,
        int $companyId,
        string $sessionId,
        string $phone,
        string $text
    ): void {
        $actions = $rule->actions ?? [];
        $chatId  = $phone . '@c.us';

        foreach ($actions as $action) {
            $type = $action['type'] ?? 'send_message';

            if ($type === 'send_message' && !empty($action['message'])) {
                dispatch(new SendAutomationMessage(
                    companyId:  $companyId,
                    sessionId:  $sessionId,
                    chatId:     $chatId,
                    message:    $action['message'],
                    ruleId:     $rule->id,
                    ruleType:   $rule->rule_type,
                    phone:      $phone,
                ));
            }

            if ($type === 'schedule_followup' && !empty($action['message'])) {
                $delayHours = $rule->delay_hours ?? 24;
                FollowUpQueue::create([
                    'company_id'      => $companyId,
                    'rule_id'         => $rule->id,
                    'session_id'      => $sessionId,
                    'contact_phone'   => $phone,
                    'message_payload' => ['text' => $action['message']],
                    'scheduled_at'    => now()->addHours($delayHours),
                    'status'          => 'pending',
                ]);
            }
        }

        AutomationLog::create([
            'company_id'    => $companyId,
            'rule_id'       => $rule->id,
            'session_id'    => $sessionId,
            'contact_phone' => $phone,
            'rule_type'     => $rule->rule_type,
            'trigger_data'  => ['text' => $text],
            'action_taken'  => json_encode($actions),
            'status'        => 'success',
        ]);
    }

    /**
     * Process inactivity rules — called from cron.
     */
    public function processInactivityRules(): void
    {
        $rules = AutomationRule::where('rule_type', 'inactivity_trigger')
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            $inactiveHours = $rule->inactivity_hours ?? 24;
            $actions       = $rule->actions ?? [];

            foreach ($actions as $action) {
                if (($action['type'] ?? '') !== 'send_message' || empty($action['message'])) continue;

                // Find contacts who had a log in the rule's session but last log > inactiveHours ago
                $cutoff = now()->subHours($inactiveHours);

                $phones = AutomationLog::where('session_id', $rule->session_id)
                    ->where('rule_id', '!=', $rule->id)
                    ->where('updated_at', '<', $cutoff)
                    ->whereNotIn('contact_phone', function ($q) use ($rule) {
                        $q->select('contact_phone')
                          ->from('automation_logs')
                          ->where('rule_id', $rule->id)
                          ->where('created_at', '>=', now()->subHours(($rule->inactivity_hours ?? 24) + 1));
                    })
                    ->distinct()
                    ->pluck('contact_phone');

                foreach ($phones as $phone) {
                    dispatch(new SendAutomationMessage(
                        companyId: $rule->company_id,
                        sessionId: $rule->session_id,
                        chatId:    $phone . '@c.us',
                        message:   $action['message'],
                        ruleId:    $rule->id,
                        ruleType:  $rule->rule_type,
                        phone:     $phone,
                    ));
                }
            }
        }
    }

    /**
     * Send due follow-up messages — called from cron.
     */
    public function processFollowUpQueue(): void
    {
        $due = FollowUpQueue::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $item) {
            try {
                $message = $item->message_payload['text'] ?? '';
                if (!$message) {
                    $item->update(['status' => 'failed', 'executed_at' => now(), 'error_message' => 'Empty message']);
                    continue;
                }

                dispatch(new SendAutomationMessage(
                    companyId: $item->company_id,
                    sessionId: $item->session_id,
                    chatId:    $item->contact_phone . '@c.us',
                    message:   $message,
                    ruleId:    $item->rule_id,
                    ruleType:  'follow_up',
                    phone:     $item->contact_phone,
                ));

                $item->update(['status' => 'sent', 'executed_at' => now()]);
            } catch (\Exception $e) {
                $item->update(['status' => 'failed', 'executed_at' => now(), 'error_message' => $e->getMessage()]);
            }
        }
    }
}
