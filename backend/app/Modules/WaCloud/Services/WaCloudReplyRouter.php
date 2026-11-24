<?php

namespace App\Modules\WaCloud\Services;

use App\Models\Company;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaPhoneNumber;
use App\Modules\WaCloud\Models\WaCloudReplyRoute;
use App\Modules\WaCloud\Models\WaCloudRouteEvent;
use App\Services\FirebasePushService;
use App\Services\StaffRealtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides how an INCOMING WA Cloud message is answered (chatbot flow / AI agent / sales-team handover /
 * auto-reply / nobody), per phone number or for all numbers, optionally by business hours — and performs the
 * staff handover. {@see \App\Modules\Webhook\Services\WebhookService::handleInbound()} calls this; with no matching
 * route the webhook behaves exactly as before (company Response Type setting + per-number AI schedule).
 */
class WaCloudReplyRouter
{
    /** First matching active route: a number-specific route beats an all-numbers one, then priority, then id. */
    public function resolve(Company $company, ?string $metaPhoneNumberId): ?WaCloudReplyRoute
    {
        $numberId = $metaPhoneNumberId
            ? WaPhoneNumber::where('company_id', $company->id)->where('phone_number_id', $metaPhoneNumberId)->value('id')
            : null;

        $routes = WaCloudReplyRoute::where('company_id', $company->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('wa_phone_number_id')->when($numberId, fn ($w) => $w->orWhere('wa_phone_number_id', $numberId)))
            ->orderByRaw('wa_phone_number_id IS NULL')   // specific number first
            ->orderByDesc('priority')->orderBy('id')
            ->get();

        foreach ($routes as $r) {
            if ($this->whenMatches($r)) {
                return $r;
            }
        }
        return null;
    }

    public function numberId(Company $company, ?string $metaPhoneNumberId): ?int
    {
        return $metaPhoneNumberId
            ? WaPhoneNumber::where('company_id', $company->id)->where('phone_number_id', $metaPhoneNumberId)->value('id')
            : null;
    }

    private function whenMatches(WaCloudReplyRoute $r): bool
    {
        // New (steps) routes carry their schedule on each step; only a legacy single-type route filters here.
        if (!empty($r->steps) || $r->when_mode === 'always') {
            return true;
        }
        $open = $this->withinHours($r);
        return $r->when_mode === 'in_hours' ? $open : !$open;
    }

    // ── Responder chain ──────────────────────────────────────────────────────

    /** Is this step switched on right now? schedule = {mode: always|in_hours|out_of_hours, days?, start?, end?} */
    public function stepActive(array $step): bool
    {
        $sc = (array) ($step['schedule'] ?? []);
        $mode = $sc['mode'] ?? 'always';
        if ($mode === 'always') {
            return true;
        }
        $open = $this->withinWindow($sc['days'] ?? [], $sc['start'] ?? null, $sc['end'] ?? null);
        return $mode === 'in_hours' ? $open : !$open;
    }

    /** Where this customer currently is in the chain (sticky for 12 h of silence), or null for "start at step 1". */
    public function currentStep(Company $company, string $phone, WaCloudReplyRoute $route): ?int
    {
        $v = Cache::get("wcrr:step:{$company->id}:{$phone}");
        return is_array($v) && ($v['route'] ?? null) === $route->id ? (int) $v['step'] : null;
    }

    public function rememberStep(Company $company, string $phone, WaCloudReplyRoute $route, int $index): void
    {
        Cache::put("wcrr:step:{$company->id}:{$phone}", ['route' => $route->id, 'step' => $index], now()->addHours(12));
    }

    /** Customer asked for a person ("agent", "human"…): jump to the next human step after $from, else just the next step. */
    public function escalationTarget(array $steps, int $from): int
    {
        for ($i = $from + 1; $i < count($steps); $i++) {
            if (in_array($steps[$i]['type'] ?? '', ['handover', 'manual'], true)) {
                return $i;
            }
        }
        return min($from + 1, count($steps) - 1);
    }

    public function wantsEscalation(WaCloudReplyRoute $route, string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        foreach ((array) ($route->escalate_keywords ?? []) as $k) {
            $k = mb_strtolower(trim((string) $k));
            if ($k !== '' && str_contains($t, $k)) {
                return true;
            }
        }
        return false;
    }

    /** True when "now" falls inside the route's office days / hours (no hours configured = always open). */
    public function withinHours(WaCloudReplyRoute $r): bool
    {
        return $this->withinWindow($r->schedule_days ?? [], $r->schedule_start, $r->schedule_end);
    }

    /** @param array<int,string>|null $days */
    public function withinWindow(?array $days, ?string $start, ?string $end): bool
    {
        $now = Carbon::now();
        $days = array_map('mb_strtolower', $days ?? []);
        if ($days !== [] && !in_array(mb_strtolower($now->format('l')), $days, true)) {
            return false;
        }
        if (!$start || !$end) {
            return true;
        }
        $cur = $now->format('H:i');
        return $start <= $end
            ? ($cur >= $start && $cur <= $end)
            : ($cur >= $start || $cur <= $end);   // overnight window
    }

    // ── Handover (lead routing) ──────────────────────────────────────────────

    /**
     * Sales-team handover = LEAD ROUTING. The chat becomes a lead assignment run by the company's own
     * Lead Assignment rule (CRM → Leads → Assignment rules): its strategy (weighted algorithm, or simple round
     * robin), its notification mode (instant auto-assign, or notify one agent at a time → Accept/Decline →
     * cascade), working hours (outside them the AI agent takes over), SLA and duplicate handling. The route only
     * adds an optional "only these people" limit.
     *
     * @param array{user_ids?:array<int>} $cfg
     * @return array{status:string,staff_id:?int,detail:?string}  status: assigned | notifying | ai | existing | skipped | failed
     */
    public function handover(Company $company, string $phone, array $cfg, ?int $routeId = null): array
    {
        $contact = \App\Models\Contact::where('company_id', $company->id)->where('phone', $phone)->first();
        if (!$contact) {
            return ['status' => 'failed', 'staff_id' => null, 'detail' => 'contact not found'];
        }
        $rule = \App\Models\LeadAssignmentRule::where('company_id', $company->id)->first()
            ?? \App\Models\LeadAssignmentRule::create(\App\Models\LeadAssignmentRule::defaultForCompany($company->id));
        $summary = $this->describeRule($rule);

        if (!$rule->auto_assign_enabled) {
            return ['status' => 'skipped', 'staff_id' => null, 'detail' => 'Lead routing: auto-assign is off — staff pick it up from the inbox'];
        }

        // One routing run per customer, not one per message: while a lead assignment is already open for this
        // contact, a new message just alerts the person who owns it (or keeps waiting for the notify cascade).
        $cur = $contact->current_assignment_id ? \App\Models\LeadAssignment::find($contact->current_assignment_id) : null;
        if ($cur && in_array($cur->status, ['pending', 'notified', 'assigned', 'accepted'], true) && $cur->created_at && $cur->created_at->gt(now()->subDays(14))) {
            if ($cur->staff_id) {
                $conv = WaConversation::where('company_id', $company->id)->where('phone', $phone)->first();
                if ($conv) {
                    $this->notify((int) $cur->staff_id, $conv, 'New message from your customer');
                }
                return ['status' => 'existing', 'staff_id' => (int) $cur->staff_id, 'detail' => 'already with the assigned agent'];
            }
            return ['status' => 'notifying', 'staff_id' => null, 'detail' => 'lead routing already in progress · ' . $summary];
        }

        $only = array_values(array_filter(array_map('intval', (array) ($cfg['user_ids'] ?? []))));
        $lead = \App\Models\Lead::where('company_id', $company->id)->where('contact_id', $contact->id)
            ->whereNotIn('stage', ['enrolled', 'lost'])->latest('id')->first();

        $a = app(\App\Services\LeadAssignment\LeadAssignmentEngine::class)->assign(
            $company, $contact, 'whatsapp_cloud', null, 'reply_route' . ($routeId ? ":{$routeId}" : ''), 'auto', $lead, $only ?: null,
        )->fresh();

        $status = $a->staff_id ? 'assigned' : ($a->ai_agent_session_id ? 'ai' : 'notifying');
        $detail = match ($status) {
            'assigned'  => $summary,
            'ai'        => 'AI agent took over (' . ($a->transfer_reason ?: 'nobody available') . ')',
            default     => $summary . ' · offered to agents one by one',
        };
        return ['status' => $status, 'staff_id' => $a->staff_id ? (int) $a->staff_id : null, 'detail' => $detail];
    }

    private function describeRule(\App\Models\LeadAssignmentRule $r): string
    {
        $strategy = ($r->strategy ?? 'algorithm') === 'round_robin' ? 'Simple round robin' : 'Weighted algorithm';
        $mode = match ($r->notification_mode) {
            'auto'   => 'instant auto-assign',
            'silent' => 'instant auto-assign, no notification',
            'uber'   => 'notify one at a time (accept / decline)',
            'hybrid' => 'hybrid notify → accept / decline → cascade',
            default  => (string) $r->notification_mode,
        };
        return "{$strategy} · {$mode}";
    }

    /** The company's current lead-routing settings, for the UI summary. */
    public function ruleSummary(Company $company): array
    {
        $r = \App\Models\LeadAssignmentRule::where('company_id', $company->id)->first();
        return $r ? [
            'auto_assign_enabled' => (bool) $r->auto_assign_enabled, 'strategy' => $r->strategy, 'notification_mode' => $r->notification_mode,
            'summary' => $this->describeRule($r),
        ] : ['auto_assign_enabled' => true, 'strategy' => 'algorithm', 'notification_mode' => 'uber', 'summary' => 'Weighted algorithm · notify one at a time (accept / decline)'];
    }

    private function notify(int $userId, WaConversation $conv, string $title): void
    {
        try {
            $name = $conv->contact_name ?: $conv->phone;
            app(FirebasePushService::class)->sendToUser($userId, 'wa_cloud_handover', $title, (string) $name,
                ['action' => 'open_wa_cloud_inbox', 'conversation_id' => (string) $conv->id]);
            StaffRealtime::toStaff($userId, 'wa_cloud_handover', ['conversation_id' => $conv->id, 'phone' => $conv->phone, 'name' => $name]);
        } catch (\Throwable $e) {
            Log::debug('wa cloud handover notify failed: ' . $e->getMessage());
        }
    }

    /** Public alias used by the automation engine's notify action. */
    public function notifyUser(int $userId, WaConversation $conv, string $title): void
    {
        $this->notify($userId, $conv, $title);
    }

    // ── Analytics log ────────────────────────────────────────────────────────

    public function record(Company $company, ?WaCloudReplyRoute $route, ?int $numberId, string $phone, string $outcome,
        ?string $finalType = null, ?int $assignedTo = null, ?string $detail = null, ?int $step = null): void
    {
        try {
            WaCloudRouteEvent::create([
                'company_id' => $company->id, 'route_id' => $route?->id, 'wa_phone_number_id' => $numberId ?? $route?->wa_phone_number_id,
                'phone' => $phone, 'response_type' => $route?->response_type ?? 'none', 'final_type' => $finalType ?? $route?->response_type, 'step' => $step,
                'outcome' => $outcome, 'assigned_to' => $assignedTo, 'detail' => $detail ? mb_substr($detail, 0, 190) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('wa cloud route event log failed: ' . $e->getMessage());
        }
    }
}
