<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\StaffPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One lead event → the people it concerns, as a mobile push + a bell entry (see StaffPush / UserNotifier).
 *   assigned          a lead was handed to a person
 *   transferred_in    a lead was moved to a person from somebody else
 *   transferred_out   a lead the person held was moved to somebody else
 *   recalled          a lead was taken back from a person
 *   new_waiting       a new lead is still unassigned after a short wait → the team heads
 *   conversation      a customer who already has a lead (not brand new) started a new conversation → the lead's owner
 *   sla               the reply time on a lead ran out → its owner
 * Runs on the queue so a bulk assign of hundreds of leads never waits on Firebase.
 */
class NotifyLeadEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $kind,
        private readonly int $leadId,
        private readonly ?int $userId = null,
        private readonly array $extra = [],
    ) {}

    public function handle(): void
    {
        try {
            $this->send();
        } catch (\Throwable $e) {
            // a notification must never break the lead action that caused it (and on a sync queue an error would travel straight back to it)
            \Illuminate\Support\Facades\Log::debug('NotifyLeadEvent failed: ' . $e->getMessage(), ['kind' => $this->kind, 'lead' => $this->leadId]);
        }
    }

    private function send(): void
    {
        $lead = Lead::with('contact:id,name,phone')->find($this->leadId);
        if (!$lead) { return; }
        $who = $lead->contact?->name ?: ($lead->contact?->phone ?: 'A lead');
        $src = $this->extra['channel'] ?? null;

        [$type, $pref, $title, $body] = match ($this->kind) {
            'assigned'        => ['lead_assigned', 'new_lead_alert', '🎯 New lead assigned', "{$who} has been assigned to you."],
            'transferred_in'  => ['lead_transferred', 'new_lead_alert', '🔀 Lead transferred to you', "{$who} was moved to you" . (!empty($this->extra['from']) ? " from {$this->extra['from']}." : '.')],
            'transferred_out' => ['lead_transferred_out', 'new_lead_alert', '🔀 Lead moved to someone else', "{$who} is now with " . ($this->extra['to'] ?? 'another team member') . '.'],
            'recalled'        => ['lead_recalled', 'new_lead_alert', '↩️ Lead taken back', "{$who} was taken back from you."],
            'new_waiting'     => ['lead_new_waiting', 'new_lead_alert', '🆕 New lead waiting', "{$who} came in" . ($lead->source ? " from {$lead->source}" : '') . ' and nobody has it yet.'],
            'conversation'    => ['lead_new_conversation', 'new_lead_alert', '💬 New conversation', "{$who} started a new conversation" . ($src ? " on {$src}" : '') . '.'],
            'sla'             => ['sla_breach', 'sla_breach_alert', '⏰ Reply time passed', "{$who} is still waiting for your reply."],
            default           => [null, null, null, null],
        };
        if (!$type) { return; }

        $recipients = $this->kind === 'new_waiting' ? $this->heads((int) $lead->company_id) : array_filter([$this->userId]);
        if ($this->kind === 'new_waiting' && $lead->assigned_to) { return; }   // someone took it in the meantime

        foreach ($recipients as $uid) {
            // a burst (a bulk assign) shows as ONE notification per kind: "🎯 New lead assigned · 7 new"
            $n = UserNotification::where('user_id', $uid)->where('type', $type)->whereNull('read_at')->where('created_at', '>=', now()->subMinutes(10))->count() + 1;
            StaffPush::toStaff((int) $uid, $pref, $type, $n > 1 ? "{$title} · {$n} new" : $title, $body, [
                'action' => $this->kind === 'new_waiting' ? 'open_leads' : 'open_lead', 'lead_id' => $lead->id, 'group' => "{$type}_{$uid}",
            ]);
        }
    }

    /** The people who watch for unassigned leads: whoever can manage leads (at most 15). @return int[] */
    private function heads(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->with('role')->limit(200)->get()
            ->filter(fn (User $u) => $u->hasPermission('leads.manage'))->take(15)->pluck('id')->all();
    }
}
