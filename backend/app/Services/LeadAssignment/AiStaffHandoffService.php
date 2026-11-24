<?php

namespace App\Services\LeadAssignment;

use App\Models\Company;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadAssignmentRule;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Modules\WaChat\Models\AiAgentSession;

class AiStaffHandoffService
{
    public function __construct(private readonly StaffScorer $scorer) {}

    public function offerToAvailableStaff(Company $company): void
    {
        $rule = LeadAssignmentRule::where('company_id', $company->id)->first();
        if (!$rule) return;

        $aiAssignments = LeadAssignment::where('company_id', $company->id)
            ->where('status', 'ai_handling')
            ->whereNull('ai_offered_at')
            ->with(['contact'])
            ->get();

        $offered = [];   // contact ids already offered in this run
        foreach ($aiAssignments as $assignment) {
            // one offer per person: a second AI-held row for the same contact (or one already out for answer) is the same conversation
            if (in_array($assignment->contact_id, $offered, true)
                || LeadAssignment::where('company_id', $company->id)->where('contact_id', $assignment->contact_id)->where('status', 'ai_offered')->where('id', '!=', $assignment->id)->exists()) {
                $assignment->update(['status' => 'dropped', 'transfer_reason' => 'Duplicate of an open AI hand-off offer for the same person.']);
                continue;
            }
            $ranked = $this->scorer->rankStaff($company, $rule);
            if ($ranked->isEmpty()) continue;

            $bestStaff = $ranked->first()->staff;
            $offered[] = $assignment->contact_id;
            $this->sendHandoffOffer($assignment, $bestStaff);

            $assignment->update([
                'status'        => 'ai_offered',
                'ai_offered_at' => now(),
            ]);

            LeadAssignmentNotification::create([
                'company_id'        => $company->id,
                'assignment_id'     => $assignment->id,
                'staff_id'          => $bestStaff->id,
                'notification_type' => 'ai_offer',
                'channel'           => 'both',
                'sent_at'           => now(),
            ]);
        }
    }

    public function handleStaffResponse(LeadAssignment $assignment, User $staff, string $response): void
    {
        if (strtolower(trim($response)) === 'yes') {
            $assignment->update([
                'staff_id'           => $staff->id,
                'status'             => 'assigned',
                'staff_confirmed_at' => now(),
                'transfer_reason'    => 'Staff took over from AI agent',
            ]);

            if ($assignment->ai_agent_session_id) {
                AiAgentSession::find($assignment->ai_agent_session_id)?->update([
                    'status' => 'handed_off',
                ]);
            }

            $availability = StaffAvailability::ensureExists($assignment->company_id, $staff->id);
            $availability->increment('current_leads_count');
        } else {
            LeadAssignmentNotification::where('assignment_id', $assignment->id)
                ->where('staff_id', $staff->id)
                ->whereNull('responded_at')
                ->latest()
                ->update(['response' => 'declined', 'responded_at' => now()]);

            // Reset so another offer can go out
            $assignment->update([
                'status'        => 'ai_handling',
                'ai_offered_at' => null,
            ]);
        }
    }

    public function detectStaffResponseFromWhatsApp(Company $company, string $staffPhone, string $message): void
    {
        $staff = User::where('company_id', $company->id)->where('phone', $staffPhone)->first();
        if (!$staff) return;

        $pendingOffer = LeadAssignment::where('company_id', $company->id)
            ->where('status', 'ai_offered')
            ->whereHas('notifications', fn($q) =>
                $q->where('staff_id', $staff->id)->where('notification_type', 'ai_offer')
            )
            ->first();

        if ($pendingOffer) {
            $this->handleStaffResponse($pendingOffer, $staff, trim($message));
        }
    }

    /** The website-widget chat this person is in right now (their newest open one), so taking the offer can open it. */
    private function widgetConversationId(LeadAssignment $assignment): ?int
    {
        try {
            $contact = $assignment->contact;
            if (!$contact) { return null; }
            $digits = preg_replace('/\D+/', '', (string) $contact->phone);
            $q = \App\Models\WidgetConversation::where('company_id', $assignment->company_id)
                ->where(fn ($w) => $w->where('contact_id', $contact->id)->when(strlen($digits) >= 8, fn ($x) => $x->orWhere('visitor_phone', 'like', '%' . substr($digits, -10))))
                ->orderByRaw("status = 'closed'")->orderByDesc('last_message_at')->orderByDesc('id');
            return $q->value('id');
        } catch (\Throwable) {
            return null;   // no widget tables / columns — the offer still works, it just does not redirect
        }
    }

    /** The lead this conversation belongs to (so taking the offer can open it when there is no website-widget chat). */
    private function leadIdFor(LeadAssignment $assignment): ?int
    {
        if ($assignment->lead_id) { return (int) $assignment->lead_id; }
        return \App\Models\Lead::where('company_id', $assignment->company_id)->where('contact_id', $assignment->contact_id)
            ->whereNotIn('stage', ['enrolled', 'lost'])->latest('id')->value('id');
    }

    /**
     * The last few messages of the conversation the AI is handling, oldest → newest, for a chat-bubble preview in the offer.
     * Looks in the website-widget chat first, then WhatsApp Cloud, then the WA Chat gateway; empty when none can be read.
     * @return array<int,array{from:string,text:string}>  from = customer | ai | staff
     */
    private function recentMessages(LeadAssignment $assignment, int $limit = 6): array
    {
        $clip = fn (string $t) => mb_strimwidth(trim($t), 0, 140, '…');
        try {
            if ($wid = $this->widgetConversationId($assignment)) {
                $rows = \App\Models\WidgetMessage::where('widget_conversation_id', $wid)->whereIn('role', ['visitor', 'agent', 'staff'])->orderByDesc('id')->limit($limit)->get(['role', 'text'])->reverse();
                $out = $rows->filter(fn ($m) => trim((string) $m->text) !== '')->map(fn ($m) => ['from' => $m->role === 'visitor' ? 'customer' : ($m->role === 'agent' ? 'ai' : 'staff'), 'text' => $clip((string) $m->text)])->values()->all();
                if ($out) { return $out; }
            }
            $contact = $assignment->contact;
            if (!$contact) { return []; }
            $rows = \Illuminate\Support\Facades\DB::table('wa_messages as m')->join('wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.company_id', $assignment->company_id)->where('c.contact_id', $contact->id)->where('m.type', 'text')
                ->orderByDesc('m.id')->limit($limit)->get(['m.direction', 'm.sender_type', 'm.content'])->reverse();
            $out = $rows->map(function ($m) use ($clip) {
                $a = json_decode((string) $m->content, true) ?: [];
                $t = (string) ($a['body'] ?? $a['text']['body'] ?? '');
                return $t === '' ? null : ['from' => $m->direction === 'inbound' ? 'customer' : ($m->sender_type === 'bot' ? 'ai' : 'staff'), 'text' => $clip($t)];
            })->filter()->values()->all();
            if ($out) { return $out; }
            $session = \App\Modules\WaChat\Models\WahaSession::where('company_id', $assignment->company_id)->first();
            if ($session) {
                $chatId = $contact->wa_id ?: preg_replace('/\D+/', '', (string) $contact->phone) . '@c.us';
                $hist = \App\Services\Topics\GatewayChatHistory::recent($assignment->company, $session, (string) $chatId, $limit);
                if ($hist) { return array_map(fn ($m) => ['from' => $m['from'] === 'us' ? 'ai' : 'customer', 'text' => $clip($m['text'])], $hist); }
            }
        } catch (\Throwable) {
            // the preview is a nicety — the offer goes out without it
        }
        return [];
    }

    private function sendHandoffOffer(LeadAssignment $assignment, User $staff): void
    {
        // what the offer shows: the last few messages as chat bubbles, and where taking the chat leads (computed once for both the socket and the push)
        $messages = $this->recentMessages($assignment);
        $aiCount = count(array_filter($messages, fn ($m) => $m['from'] === 'ai'));
        $widgetChat = $this->widgetConversationId($assignment);
        $leadId = $this->leadIdFor($assignment);

        // Emit via Node.js socket — fire and forget
        try {
            $internalKey = config('services.internal.key', '');
            $nodeUrl = config('services.chat.url', 'http://localhost:3005');
            $contact = $assignment->contact;

            \Illuminate\Support\Facades\Http::withHeaders(['X-Internal-Key' => $internalKey])
                ->post("{$nodeUrl}/api/internal/emit-notification", [
                    'type'     => 'ai_handoff_offer',
                    'staff_id' => $staff->id,
                    'data'     => [
                        'assignment_id'  => $assignment->id,
                        'contact_name'   => $contact?->name ?? 'Unknown',
                        'contact_phone'  => $contact?->phone ?? '',
                        'conversation_summary' => $contact?->conversation_summary ?? 'AI is handling this conversation.',
                        'ai_message_count' => $aiCount,
                        'widget_conversation_id' => $widgetChat,
                        'lead_id' => $leadId,
                        'messages' => $messages,
                    ],
                ]);
        } catch (\Throwable) {
            // Non-critical — continue
        }

        // the same offer as a mobile push; tapping it (or having the app open) shows the Yes / No card
        $contact = $assignment->contact;
        \App\Services\StaffPush::toStaff($staff->id, 'ai_handoff_offers', 'ai_handoff_offer', '🤖 AI Agent offer',
            ($contact?->name ?: 'A customer') . ' — AI is handling this chat. Want to take over?', [
                'action' => 'ai_handoff_offer', 'assignment_id' => $assignment->id,
                'contact_name' => $contact?->name ?? 'Unknown', 'contact_phone' => $contact?->phone ?? '',
                'conversation_summary' => $contact?->conversation_summary ?? 'AI is handling this conversation.', 'ai_message_count' => $aiCount,
                'widget_conversation_id' => $widgetChat, 'lead_id' => $leadId,
                'messages' => json_encode(array_slice($messages, -5), JSON_UNESCAPED_UNICODE),   // FCM data is text: the bubbles travel as JSON (kept short)
            ]);
    }
}
