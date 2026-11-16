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
        if ($r->when_mode === 'always') {
            return true;
        }
        $open = $this->withinHours($r);
        return $r->when_mode === 'in_hours' ? $open : !$open;
    }

    /** True when "now" falls inside the route's office days / hours (no hours configured = always open). */
    public function withinHours(WaCloudReplyRoute $r): bool
    {
        $now = Carbon::now();
        $days = array_map('mb_strtolower', $r->schedule_days ?? []);
        if ($days !== [] && !in_array(mb_strtolower($now->format('l')), $days, true)) {
            return false;
        }
        if (!$r->schedule_start || !$r->schedule_end) {
            return true;
        }
        $cur = $now->format('H:i');
        return $r->schedule_start <= $r->schedule_end
            ? ($cur >= $r->schedule_start && $cur <= $r->schedule_end)
            : ($cur >= $r->schedule_start || $cur <= $r->schedule_end);   // overnight window
    }

    // ── Handover ─────────────────────────────────────────────────────────────

    /**
     * Hand the conversation to a sales-team member: pick one (round-robin or least busy among the chosen users),
     * assign the inbox conversation, and notify them (push + live socket). Returns the user id, or null if nobody
     * could be picked.
     *
     * @param array{user_ids?:array<int>,strategy?:string} $cfg
     */
    public function handover(Company $company, string $phone, array $cfg, ?int $routeId = null): ?int
    {
        $conv = WaConversation::where('company_id', $company->id)->where('phone', $phone)->first();
        if (!$conv) {
            return null;
        }

        // Keep the same person on a customer who is already assigned to an active staff member.
        if ($conv->assigned_to && User::where('company_id', $company->id)->where('id', $conv->assigned_to)->where('is_active', true)->exists()) {
            $this->notify((int) $conv->assigned_to, $conv, 'New message from your customer');
            return (int) $conv->assigned_to;
        }

        $uid = $this->pickStaff($company, (array) ($cfg['user_ids'] ?? []), (string) ($cfg['strategy'] ?? 'round_robin'), $routeId);
        if (!$uid) {
            return null;
        }
        $conv->update(['assigned_to' => $uid, 'status' => 'open']);
        $this->notify($uid, $conv, 'New conversation assigned to you');
        return $uid;
    }

    /** @param array<int> $userIds */
    public function pickStaff(Company $company, array $userIds, string $strategy, ?int $routeId = null): ?int
    {
        $pool = User::where('company_id', $company->id)->where('is_active', true)
            ->when($userIds, fn ($q) => $q->whereIn('id', $userIds))
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (!$pool) {
            return null;
        }

        if ($strategy === 'least_busy') {
            $open = WaConversation::where('company_id', $company->id)->where('status', 'open')->whereIn('assigned_to', $pool)
                ->groupBy('assigned_to')->select('assigned_to', DB::raw('count(*) as c'))->pluck('c', 'assigned_to');
            usort($pool, fn ($a, $b) => ($open[$a] ?? 0) <=> ($open[$b] ?? 0) ?: $a <=> $b);
            return $pool[0];
        }

        // round robin: a per-route (or per-company) rotating pointer
        $key = 'wcrr:rr:' . $company->id . ':' . ($routeId ?? 0);
        $i = (int) Cache::get($key, 0);
        Cache::put($key, $i + 1, now()->addDays(30));
        return $pool[$i % count($pool)];
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
        ?string $finalType = null, ?int $assignedTo = null, ?string $detail = null): void
    {
        try {
            WaCloudRouteEvent::create([
                'company_id' => $company->id, 'route_id' => $route?->id, 'wa_phone_number_id' => $numberId ?? $route?->wa_phone_number_id,
                'phone' => $phone, 'response_type' => $route?->response_type ?? 'none', 'final_type' => $finalType ?? $route?->response_type,
                'outcome' => $outcome, 'assigned_to' => $assignedTo, 'detail' => $detail ? mb_substr($detail, 0, 190) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('wa cloud route event log failed: ' . $e->getMessage());
        }
    }
}
