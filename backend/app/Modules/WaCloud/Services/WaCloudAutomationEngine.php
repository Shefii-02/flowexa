<?php

namespace App\Modules\WaCloud\Services;

use App\Models\Company;
use App\Models\MessageLog;
use App\Models\WaConversation;
use App\Modules\WaCloud\Models\WaCloudApiConfig;
use App\Modules\WaCloud\Models\WaCloudAutomationLog;
use App\Modules\WaCloud\Models\WaCloudAutomationRule;
use App\Support\Meta\MetaGraph;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Execution engine for WA Cloud (Meta Cloud API) automation rules.
 *
 * Two entry points:
 *  - runInbound()   — called from WebhookService::handleInbound() for message-driven
 *                     rules (welcome_message, keyword_trigger, out_of_office, lead_qualifier).
 *  - runScheduled() — called from the wa-cloud:run-automations command for time-driven
 *                     rules (follow_up_reminder, follow_up_agent, inactivity_trigger).
 *
 * All sends go through the Meta Cloud API `/{wa_phone_id}/messages` endpoint and
 * every fire (or skip) is written to wa_cloud_automation_logs.
 */
class WaCloudAutomationEngine
{
    public function __construct(private readonly WaCloudMessageService $templateSender = new WaCloudMessageService())
    {
    }

    // ── Inbound (message-driven) ─────────────────────────────────────────────

    /**
     * @param string      $phone         customer phone (digits, as stored on the contact)
     * @param string      $text          inbound message text (empty for non-text)
     * @param string|null $phoneNumberId Meta phone_number_id the message arrived on
     */
    public function runInbound(Company $company, string $phone, string $text, ?string $phoneNumberId = null): void
    {
        $rules = WaCloudAutomationRule::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereIn('rule_type', ['welcome_message', 'keyword_trigger', 'out_of_office', 'lead_qualifier'])
            ->orderByDesc('priority')->orderBy('id')
            ->get()
            ->filter(fn (WaCloudAutomationRule $r) => $this->numberMatches($r, $company, $phoneNumberId));

        if ($rules->isEmpty()) {
            return;
        }

        $isFirstMessage = MessageLog::where('company_id', $company->id)
            ->where('phone', $phone)->where('direction', 'inbound')->count() <= 1;

        $lower = mb_strtolower(trim($text));

        foreach ($rules as $rule) {
            $shouldFire = match ($rule->rule_type) {
                'welcome_message' => $isFirstMessage,
                'keyword_trigger' => $lower !== '' && $this->matchesKeyword($rule, $lower),
                'out_of_office'   => $this->outsideSchedule($rule),
                'lead_qualifier'  => $lower !== '' && ($rule->keywords ? $this->matchesKeyword($rule, $lower) : $isFirstMessage),
                default           => false,
            };

            if (!$shouldFire) {
                continue;
            }

            // Throttle: welcome once per contact ever; the rest once per contact
            // per (delay_hours ?: 6) hours so we don't hammer a chatty customer.
            $window = $rule->rule_type === 'welcome_message'
                ? null
                : now()->subHours($rule->delay_hours ?: 6);

            if ($this->alreadyFired($rule, $phone, $window)) {
                continue;
            }

            $this->applyActions($company, $rule, $phone, $phoneNumberId, [
                'trigger' => $rule->rule_type,
                'text'    => $text,
            ]);
        }
    }

    // ── Scheduled (time-driven) ──────────────────────────────────────────────

    public function runScheduled(): void
    {
        $rules = WaCloudAutomationRule::where('is_active', true)
            ->whereIn('rule_type', ['follow_up_reminder', 'follow_up_agent', 'inactivity_trigger'])
            ->orderByDesc('priority')->orderBy('id')
            ->get()
            ->groupBy('company_id');

        foreach ($rules as $companyId => $companyRules) {
            $company = Company::find($companyId);
            if (!$company || !$company->wa_phone_id) {
                continue;
            }

            foreach ($companyRules as $rule) {
                $hours = $rule->rule_type === 'inactivity_trigger'
                    ? ($rule->inactivity_hours ?: 24)
                    : ($rule->delay_hours ?: 24);

                $cutoff = now()->subHours($hours);

                $conversations = WaConversation::where('company_id', $company->id)
                    ->where('last_message_at', '<', $cutoff)
                    // still recent enough that a follow-up is worthwhile (within 2x the window)
                    ->where('last_message_at', '>', now()->subHours($hours * 2 + 24))
                    ->when($rule->wa_phone_number_id, fn ($q) => $q->where('wa_phone_number_id', $rule->wa_phone_number_id))
                    ->when(
                        in_array($rule->rule_type, ['follow_up_reminder', 'follow_up_agent'], true),
                        fn ($q) => $q->whereIn('status', ['open', 'pending', 'active'])
                    )
                    ->limit(200)
                    ->get();

                foreach ($conversations as $conv) {
                    if ($this->alreadyFired($rule, $conv->phone, now()->subHours($hours))) {
                        continue;
                    }

                    $this->applyActions($company, $rule, $conv->phone, $conv->wa_phone_number_id, [
                        'trigger'         => $rule->rule_type,
                        'conversation_id' => $conv->id,
                        'idle_hours'      => $hours,
                    ]);
                }
            }
        }
    }

    // ── Action execution ────────────────────────────────────────────────────

    /** @param array<string,mixed> $triggerData */
    private function applyActions(
        Company $company,
        WaCloudAutomationRule $rule,
        string $phone,
        int|string|null $phoneNumberId,
        array $triggerData,
    ): void {
        foreach (($rule->actions ?? []) as $action) {
            $type = $action['type'] ?? 'send_message';

            try {
                [$ok, $summary, $result, $error] = match ($type) {
                    'send_message', 'send_text', 'text', 'reply'
                        => $this->doSendText($company, $phone, (string) ($action['message'] ?? $action['text'] ?? '')),
                    'send_template'
                        => $this->doSendTemplate($company, $phone, (int) ($action['config_id'] ?? 0), (array) ($action['variables'] ?? [])),
                    'assign_agent', 'notify_agent', 'create_task', 'add_label', 'mark_lead'
                        => [true, "logged: {$type}", ['note' => 'no-op action recorded'], null],
                    default
                        => [false, "unknown action: {$type}", null, "Unsupported action type '{$type}'"],
                };
            } catch (\Throwable $e) {
                $ok = false;
                $summary = "error: {$type}";
                $result = null;
                $error = $e->getMessage();
            }

            WaCloudAutomationLog::create([
                'company_id'         => $company->id,
                'rule_id'            => $rule->id,
                'wa_phone_number_id' => is_numeric($phoneNumberId) ? (int) $phoneNumberId : $rule->wa_phone_number_id,
                'contact_phone'      => $phone,
                'rule_type'          => $rule->rule_type,
                'trigger_data'       => $triggerData,
                'action_taken'       => $summary,
                'result'             => $result,
                'status'             => $ok ? 'success' : 'failed',
                'error_message'      => $error,
            ]);
        }
    }

    /** @return array{0:bool,1:string,2:?array,3:?string} */
    private function doSendText(Company $company, string $phone, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [false, 'send_message (empty)', null, 'No message text configured on the rule.'];
        }

        $token = MetaGraph::accessToken($company);
        if (!$token || !$company->wa_phone_id) {
            return [false, 'send_message', null, 'WhatsApp Cloud API credentials not configured.'];
        }

        $res = Http::withToken($token)->timeout(15)->post(MetaGraph::messagesUrl($company->wa_phone_id), [
            'messaging_product' => 'whatsapp',
            'to'                => preg_replace('/\D/', '', $phone),
            'type'              => 'text',
            'text'              => ['body' => mb_substr($text, 0, 4096), 'preview_url' => false],
        ]);

        if ($res->successful()) {
            $waId = $res->json('messages.0.id');
            MessageLog::create([
                'company_id'    => $company->id,
                'wa_message_id' => $waId,
                'direction'     => 'outbound',
                'type'          => 'text',
                'phone'         => $phone,
                'content'       => $text,
                'status'        => 'sent',
                'cost'          => 1,
            ]);
            return [true, 'send_message', ['wa_message_id' => $waId], null];
        }

        return [false, 'send_message', null, $res->json('error.message') ?? 'Meta send failed.'];
    }

    /** @param array<string,mixed> $vars @return array{0:bool,1:string,2:?array,3:?string} */
    private function doSendTemplate(Company $company, string $phone, int $configId, array $vars): array
    {
        $config = WaCloudApiConfig::where('company_id', $company->id)->find($configId);
        if (!$config) {
            return [false, 'send_template', null, "Config #{$configId} not found."];
        }
        if ($config->template_status !== 'approved') {
            return [false, 'send_template', null, "Template not approved (status: {$config->template_status})."];
        }

        [$ok, $waId, $error] = $this->templateSender->sendUtility($company, $config, $phone, $vars);

        return $ok
            ? [true, "send_template #{$configId}", ['wa_message_id' => $waId], null]
            : [false, "send_template #{$configId}", null, $error];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function numberMatches(WaCloudAutomationRule $rule, Company $company, ?string $phoneNumberId): bool
    {
        if (!$rule->wa_phone_number_id || !$phoneNumberId) {
            return true; // rule covers all numbers, or we can't tell which number this was
        }

        $row = \App\Models\WaPhoneNumber::where('company_id', $company->id)
            ->where('id', $rule->wa_phone_number_id)->first();

        return $row && (string) $row->phone_number_id === (string) $phoneNumberId;
    }

    private function matchesKeyword(WaCloudAutomationRule $rule, string $lowerText): bool
    {
        foreach (($rule->keywords ?? []) as $kw) {
            $kw = mb_strtolower(trim((string) $kw));
            if ($kw !== '' && str_contains($lowerText, $kw)) {
                return true;
            }
        }
        return false;
    }

    /** True when "now" (company timezone-naive) is outside the rule's working window. */
    private function outsideSchedule(WaCloudAutomationRule $rule): bool
    {
        $now  = Carbon::now();
        $days = $rule->schedule_days ?? [];

        if ($days !== [] && !in_array(mb_strtolower($now->format('l')), array_map('mb_strtolower', $days), true)) {
            return true;
        }

        if (!$rule->schedule_start || !$rule->schedule_end) {
            return false; // day matched (or no day filter) and no time window → treat as in-hours
        }

        $start = Carbon::createFromFormat('H:i', substr((string) $rule->schedule_start, 0, 5));
        $end   = Carbon::createFromFormat('H:i', substr((string) $rule->schedule_end, 0, 5));
        $cur   = Carbon::createFromFormat('H:i', $now->format('H:i'));

        // Overnight window (e.g. 18:00–09:00) means "open" spans midnight.
        return $start->lte($end)
            ? $cur->lt($start) || $cur->gt($end)
            : $cur->gt($end) && $cur->lt($start);
    }

    private function alreadyFired(WaCloudAutomationRule $rule, string $phone, ?Carbon $since): bool
    {
        return WaCloudAutomationLog::where('rule_id', $rule->id)
            ->where('contact_phone', $phone)
            ->where('status', 'success')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->exists();
    }
}
