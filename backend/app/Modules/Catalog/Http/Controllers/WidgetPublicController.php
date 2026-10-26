<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ChatWidget;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Modules\Catalog\WidgetChatAgent;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Str;

/**
 * Public, unauthenticated endpoints the embedded widget calls from a customer's website.
 * Origin is checked per-widget; abuse is bounded by a throttle on the routes.
 */
class WidgetPublicController extends Controller
{
    public function __construct(
        private readonly WidgetChatAgent $agent,
        private readonly \App\Modules\Catalog\WidgetFlowRunner $flow,
        private readonly \App\Modules\Catalog\LeadAlertService $alerts,
    ) {}

    /** Widget config for the loader script. */
    public function bootstrap(Request $request, string $key): JsonResponse
    {
        $widget = $this->widget($key);
        if (!$widget || !$widget->is_active) {
            return response()->json(['message' => 'Widget not found'], 404);
        }
        if (!$widget->originAllowed($request->headers->get('Origin'))) {
            return response()->json(['message' => 'This domain is not allowed to load the widget'], 403);
        }

        return response()->json([
            'agent_name' => $widget->agent_name,
            'greeting'   => $widget->greeting,
            'has_flow'   => (bool) $widget->flow_builder_id,
            'live_chat'  => (bool) $widget->live_chat_enabled,
            'live_wait_seconds' => (int) ($widget->live_wait_seconds ?: 90),
            'branding'   => $widget->branding ?: (object) [],
        ]);
    }

    /** One visitor turn. Creates the conversation on the first call. */
    public function chat(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
            'message'       => ['required_without:action', 'nullable', 'string', 'max:2000'],
            'action'        => ['nullable', 'in:reset,back,menu,yes,no'],
            'page_url'      => ['nullable', 'string', 'max:500'],
            'reply_to_id'   => ['nullable', 'integer'],
            'name'          => ['nullable', 'string', 'min:2', 'max:80'],
            'phone'         => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
        ]);

        $widget = $this->widget($key);
        if (!$widget || !$widget->is_active) {
            return response()->json(['message' => 'Widget not found'], 404);
        }
        if (!$widget->originAllowed($request->headers->get('Origin'))) {
            return response()->json(['message' => 'Origin not allowed'], 403);
        }

        $action  = $data['action'] ?? null;
        $message = (string) ($data['message'] ?? '');

        // Reset: end this conversation and hand the browser a fresh session. The widget redraws its greeting.
        if ($action === 'reset') {
            if (!empty($data['session_token'])) {
                WidgetConversation::where('chat_widget_id', $widget->id)->where('session_token', $data['session_token'])
                    ->update(['status' => 'closed', 'flow_node_id' => null, 'flow_state' => null]);
            }
            return response()->json(['session_token' => null, 'reply' => null, 'options' => [], 'controls' => [], 'status' => 'closed']);
        }

        $convo = null;
        if (!empty($data['session_token'])) {
            $convo = WidgetConversation::where('chat_widget_id', $widget->id)
                ->where('session_token', $data['session_token'])->first();
        }
        if (!$convo) {
            $convo = WidgetConversation::create([
                'chat_widget_id' => $widget->id,
                'company_id'     => $widget->company_id,
                'session_token'  => (string) Str::uuid(),
                'status'         => 'active',
                'page_url'       => $data['page_url'] ?? null,
                'visitor_ip'     => $request->ip(),
                'last_message_at' => now(),
            ]);
            $widget->increment('conversations_count');
        }

        // Name + mobile collected by the widget before the chat starts.
        if (!empty($data['name']) && !empty($data['phone'])
            && ($convo->visitor_name !== trim($data['name']) || $convo->visitor_phone !== trim($data['phone']))) {
            $convo->update(['visitor_name' => trim($data['name']), 'visitor_phone' => trim($data['phone'])]);
            $this->closePrevious($widget, $convo->fresh());
            $this->saveToCrm($widget, $convo->fresh());
        }

        // A closed conversation (resolved, or handed to staff) restarts cleanly on the next message.
        if ($convo->status === 'closed') {
            $convo->update(['status' => 'active', 'flow_node_id' => null, 'flow_state' => null, 'live_status' => null]);
        }

        // Nobody took the request and the AI fallback is on: the AI agent (knowledge base) keeps helping
        // until the issue is solved or a staff member picks the chat up.
        if ($convo->ai_active && $convo->live_status === 'missed') {
            if ($action === 'yes') {
                $convo->update(['ai_active' => false, 'live_status' => 'ended', 'status' => 'closed', 'case_status' => 'closed', 'closed_at' => now()]);
                $reply = 'Glad we could help! 😊 This conversation is now closed.';
                WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $reply]);
                return response()->json(['session_token' => $convo->session_token, 'reply' => $reply, 'options' => [], 'controls' => [], 'closed' => true, 'status' => 'closed']);
            }
            if ($action === 'no') {
                $convo->update(['ai_active' => false, 'case_status' => 'pending']);
                $convo->flag('not_solved');
                $reply = 'Thank you for your patience. Our staff will connect with you soon. 🙏';
                WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $reply]);
                $this->alerts->notifyLiveRequest($widget, $convo->fresh(), true);
                return response()->json(['session_token' => $convo->session_token, 'reply' => $reply, 'options' => [], 'controls' => [], 'closed' => true, 'status' => $convo->status]);
            }
            if ($message !== '') {
                $reply = $this->agent->reply($widget, $convo, $message);
                $convo->update(['last_message_at' => now()]);
                return response()->json([
                    'session_token' => $convo->session_token, 'reply' => $reply, 'options' => [],
                    'controls' => [['action' => 'yes', 'label' => '✅ Issue solved'], ['action' => 'no', 'label' => '❌ Not solved']], 'closed' => false, 'ai' => true, 'status' => $convo->status,
                ]);
            }
        }

        // With a live agent (or still waiting for one) the visitor's text goes to staff, not the bot.
        if (in_array($convo->live_status, ['waiting', 'live', 'missed'], true) && $message !== '') {
            WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => $message,
                'reply_to_id' => $this->quotable($convo, $data['reply_to_id'] ?? null)]);
            $convo->update(['last_message_at' => now()]);
            return response()->json([
                'session_token' => $convo->session_token, 'reply' => null, 'options' => [], 'controls' => [],
                'closed' => false, 'live_status' => $convo->live_status, 'status' => $convo->status,
            ]);
        }

        $flowTurn = $this->flow->handle($widget, $convo, $message, $action);

        // The bot has nothing more to offer: next level is a person from the team (the AI only steps in
        // if nobody is available — see livePoll). Name + mobile were already collected by the widget.
        if ($flowTurn === null && $widget->live_chat_enabled && $message !== '') {
            $reply = 'Let me connect you with our team…';
            WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => $message]);
            WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $reply]);
            $convo->update(['case_status' => 'pending', 'last_message_at' => now()]);
            return response()->json([
                'session_token' => $convo->session_token, 'reply' => $reply, 'options' => [], 'controls' => [],
                'closed' => false, 'start_live' => true, 'status' => $convo->status,
            ]);
        }
        $reply = $flowTurn['reply'] ?? $this->agent->reply($widget, $convo, $message);

        return response()->json([
            'session_token' => $convo->session_token,
            'reply'         => $reply,
            'options'       => $flowTurn['options'] ?? [],
            'controls'      => $flowTurn['controls'] ?? [],
            'closed'        => $flowTurn['closed'] ?? false,
            'start_live'    => $flowTurn['start_live'] ?? false,
            'status'        => $convo->fresh()->status,
        ]);
    }


    /**
     * Visitor asks for a live agent. Name + mobile are required (the widget pre-fills them from the
     * browser's saved details so a returning visitor only has to confirm or edit). Also used to edit
     * the details while already waiting.
     */
    public function liveStart(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
            'name'          => ['required', 'string', 'min:2', 'max:80'],
            'phone'         => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'page_url'      => ['nullable', 'string', 'max:500'],
        ], ['phone.regex' => 'Enter a valid mobile number.']);

        $widget = $this->liveWidget($request, $key);
        if ($widget instanceof JsonResponse) return $widget;

        $convo = $this->findConvo($widget, $data['session_token'] ?? null)
            ?? WidgetConversation::create([
                'chat_widget_id' => $widget->id, 'company_id' => $widget->company_id,
                'session_token' => (string) Str::uuid(), 'status' => 'active',
                'page_url' => $data['page_url'] ?? null, 'visitor_ip' => $request->ip(), 'last_message_at' => now(),
            ]);
        if ($convo->wasRecentlyCreated) $widget->increment('conversations_count');

        $details = ['visitor_name' => trim($data['name']), 'visitor_phone' => preg_replace('/\s+/', ' ', trim($data['phone']))];

        if (in_array($convo->live_status, ['waiting', 'live'], true)) {
            $convo->update($details);                       // edit while waiting / chatting
            $this->saveToCrm($widget, $convo->fresh());
        } elseif ($existing = $this->openCaseFor($widget, $convo, $details['visitor_phone'])) {
            // Same visitor is being served by a staff member right now (e.g. a second tab): join that chat, don't queue twice.
            $convo->update($details + ['duplicate_of_id' => $existing->id, 'case_status' => 'closed', 'closed_at' => now()]);
            $existing->flag('repeated');
            $this->sysMsg($existing, "{$details['visitor_name']} contacted again about the same issue.");
            $existing->update(['last_message_at' => now()]);
            return response()->json($this->liveState($widget, $existing->fresh(), 0) + ['repeat' => true]);
        } else {
            $convo->update($details);
            $this->closePrevious($widget, $convo->fresh());
            $convo->update([
                'case_status' => 'unread', 'status' => 'active', 'live_status' => 'waiting', 'live_requested_at' => now(),
                'live_accepted_at' => null, 'assigned_user_id' => null, 'staff_name' => null,
                'callback_confirmed_at' => null, 'last_message_at' => now(),
            ]);
            $this->sysMsg($convo, "Live chat requested by {$details['visitor_name']} ({$details['visitor_phone']}).");
            $this->saveToCrm($widget, $convo->fresh());
            $this->alerts->notifyLiveRequest($widget, $convo->fresh());
        }

        return response()->json($this->liveState($widget, $convo->fresh(), 0));
    }

    /** Polled by the widget while waiting / chatting: status + any new staff messages after $after. */
    public function livePoll(Request $request, string $key): JsonResponse
    {
        $widget = $this->liveWidget($request, $key);
        if ($widget instanceof JsonResponse) return $widget;

        $convo = $this->findConvo($widget, (string) $request->query('session_token'));
        if (!$convo) return response()->json(['message' => 'Conversation not found'], 404);

        // Nobody accepted within the wait window → switch to "we'll contact you".
        if ($convo->live_status === 'waiting'
            && $convo->live_requested_at?->lt(now()->subSeconds((int) ($widget->live_wait_seconds ?: 90)))) {
            if ($convo->update(['live_status' => 'missed'])) {
                $convo->flag('no_staff');
                $this->sysMsg($convo, 'No staff member was available in time.');
                if ($this->aiFallbackAvailable($widget)) {
                    $convo->update(['ai_active' => true]);
                    $this->sysMsg($convo, 'In the meantime our AI assistant can help — just ask your question here. If a staff member becomes free, they will join this chat.');
                }
                $this->alerts->notifyLiveRequest($widget, $convo, true);
            }
        }

        return response()->json($this->liveState($widget, $convo->fresh(), (int) $request->query('after', 0)));
    }

    /** After a missed request the visitor checks / edits their details so staff can contact them. */
    public function liveConfirm(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'name'          => ['required', 'string', 'min:2', 'max:80'],
            'phone'         => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
        ], ['phone.regex' => 'Enter a valid mobile number.']);

        $widget = $this->liveWidget($request, $key);
        if ($widget instanceof JsonResponse) return $widget;
        $convo = $this->findConvo($widget, $data['session_token']);
        if (!$convo) return response()->json(['message' => 'Conversation not found'], 404);

        $convo->update([
            'visitor_name' => trim($data['name']), 'visitor_phone' => trim($data['phone']),
            'callback_confirmed_at' => now(),
        ]);
        $this->saveToCrm($widget, $convo->fresh());
        $this->sysMsg($convo, "Thank you {$convo->visitor_name}. Our staff will connect with you soon on {$convo->visitor_phone}. 🙏");
        $this->alerts->notifyLiveRequest($widget, $convo->fresh(), true);

        // No AI fallback → this is the end of the road for the visitor; staff follow up from the inbox.
        return response()->json($this->liveState($widget, $convo->fresh(), (int) $request->input('after', 0))
            + ['closed' => !$convo->ai_active]);
    }

    /** Visitor sends a photo / voice note / file (max 2 MB) to the staff member in a live chat. */
    public function liveUpload(Request $request, string $key): JsonResponse
    {
        $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
            'file'          => ['required', 'file'],
            'text'          => ['nullable', 'string', 'max:500'],
            'reply_to_id'   => ['nullable', 'integer'],
            'name'          => ['nullable', 'string', 'min:2', 'max:80'],
            'phone'         => ['nullable', 'string', 'regex:/^\\+?[0-9 ()-]{7,20}$/'],
            'page_url'      => ['nullable', 'string', 'max:500'],
        ]);
        // Photos / files can be shared at any point of the chat (bot or live) — staff see them in the inbox.
        $widget = $this->widget($key);
        if (!$widget || !$widget->is_active) return response()->json(['message' => 'Widget not found'], 404);
        if (!$widget->originAllowed($request->headers->get('Origin'))) return response()->json(['message' => 'Origin not allowed'], 403);

        $convo = $this->findConvo($widget, $request->input('session_token'));
        if (!$convo) {
            $convo = WidgetConversation::create([
                'chat_widget_id' => $widget->id, 'company_id' => $widget->company_id, 'session_token' => (string) Str::uuid(),
                'status' => 'active', 'page_url' => $request->input('page_url'), 'visitor_ip' => $request->ip(), 'last_message_at' => now(),
                'visitor_name' => $request->input('name'), 'visitor_phone' => $request->input('phone'),
            ]);
            $widget->increment('conversations_count');
        }

        $cols = \App\Modules\Catalog\Support\LiveChatSupport::storeUpload($request->file('file'), $convo, ['image', 'file']); // visitors: photos & files only (voice is staff-only)
        $msg = WidgetMessage::create(array_merge([
            'widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => (string) $request->input('text', ''),
            'reply_to_id' => $this->quotable($convo, $request->input('reply_to_id')),
        ], $cols));
        $convo->update(['last_message_at' => now()]);

        return response()->json(['message' => \App\Modules\Catalog\Support\LiveChatSupport::format($msg), 'session_token' => $convo->session_token]);
    }

    /** Whole conversation for this browser's session token — lets the widget restore itself after a page refresh. */
    public function history(Request $request, string $key): JsonResponse
    {
        $widget = $this->widget($key);
        if (!$widget || !$widget->is_active) return response()->json(['message' => 'Widget not found'], 404);
        if (!$widget->originAllowed($request->headers->get('Origin'))) return response()->json(['message' => 'Origin not allowed'], 403);

        $convo = $this->findConvo($widget, (string) $request->query('session_token'));
        if (!$convo || $convo->status === 'closed' || $convo->live_status === 'ended') {
            return response()->json(['active' => false]);
        }
        $messages = $convo->messages()->latest('id')->limit(200)->get()->reverse()
            ->map(fn ($m) => \App\Modules\Catalog\Support\LiveChatSupport::format($m))->values();

        return response()->json([
            'active' => $messages->isNotEmpty(), 'session_token' => $convo->session_token, 'live_status' => $convo->live_status,
            'name' => $convo->visitor_name, 'phone' => $convo->visitor_phone, 'callback_confirmed' => (bool) $convo->callback_confirmed_at,
            'ai_active' => (bool) $convo->ai_active && $convo->live_status === 'missed',
            'can_request_live' => $widget->live_chat_enabled && $convo->case_status === 'pending' && !$convo->live_status,
            'messages' => $messages,
        ]);
    }

    /** AI fallback = enabled on the widget AND the company has an AI provider key configured. */
    private function aiFallbackAvailable(ChatWidget $widget): bool
    {
        return (bool) $widget->ai_fallback_enabled && $this->agent->isConfigured($widget);
    }

    private function quotable(WidgetConversation $convo, $id): ?int
    {
        return $id ? WidgetMessage::where('widget_conversation_id', $convo->id)->where('id', $id)->value('id') : null;
    }

    /**
     * Save the visitor's name + mobile as a CRM contact and lead (deduped by number), so staff can find
     * them in Contacts / Leads and the normal lead-assignment pipeline runs. Never blocks the chat.
     */
    private function saveToCrm(ChatWidget $widget, WidgetConversation $convo): void
    {
        try {
            $r = app(\App\Modules\Catalog\LeadIntake::class)->capture(
                $widget->company, ['name' => $convo->visitor_name, 'phone' => $convo->visitor_phone, 'source_detail' => 'live chat request'],
                'website_widget', "widget:{$convo->id}",
                originType: 'widget', originId: $widget->id, originLabel: $widget->name,
            );
            $convo->update(array_filter(['contact_id' => $r['contact']?->id, 'lead_id' => $r['lead']?->id]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Live chat: saving to CRM failed', ['error' => $e->getMessage()]);
        }
    }

    private function liveState(ChatWidget $widget, WidgetConversation $convo, int $after): array
    {
        $wait = (int) ($widget->live_wait_seconds ?: 90);
        return [
            'session_token' => $convo->session_token,
            'live_status'   => $convo->live_status,
            'staff_name'    => $convo->staff_name,
            'name'          => $convo->visitor_name,
            'phone'         => $convo->visitor_phone,
            'callback_confirmed' => (bool) $convo->callback_confirmed_at,
            'ai_active'     => (bool) $convo->ai_active,
            'wait_remaining' => $convo->live_status === 'waiting' && $convo->live_requested_at
                ? max(0, $wait - (int) $convo->live_requested_at->diffInSeconds(now())) : 0,
            'messages' => $convo->messages()->whereIn('role', ['staff', 'system'])->where('id', '>', $after)
                ->get()->map(fn ($m) => \App\Modules\Catalog\Support\LiveChatSupport::format($m))->values(),
        ];
    }

    private function liveWidget(Request $request, string $key): ChatWidget|JsonResponse
    {
        $widget = $this->widget($key);
        if (!$widget || !$widget->is_active || !$widget->live_chat_enabled) {
            return response()->json(['message' => 'Live chat is not available'], 404);
        }
        if (!$widget->originAllowed($request->headers->get('Origin'))) {
            return response()->json(['message' => 'Origin not allowed'], 403);
        }
        return $widget;
    }

    /** An unattended or in-progress request from the same mobile number (last 24h) on this widget. */
    private function openCaseFor(ChatWidget $widget, WidgetConversation $current, string $phone): ?WidgetConversation
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return WidgetConversation::where('chat_widget_id', $widget->id)->where('id', '!=', $current->id)
            ->where('live_status', 'live')
            ->where('last_message_at', '>', now()->subDay())
            ->whereRaw("REGEXP_REPLACE(visitor_phone, '[^0-9]', '') = ?", [$digits])
            ->latest('id')->first();
    }

    /**
     * A visitor starting a fresh chat (same mobile number) must not leave their earlier chats dangling as
     * "open": close them (status), end any request still waiting. Chats a staff member is live in are left alone.
     */
    private function closePrevious(ChatWidget $widget, WidgetConversation $current): void
    {
        $digits = preg_replace('/\D+/', '', (string) $current->visitor_phone);
        if (strlen($digits) < 7) return;
        WidgetConversation::where('chat_widget_id', $widget->id)->where('id', '!=', $current->id)
            ->where('status', '!=', 'closed')
            ->where(fn ($q) => $q->whereNull('live_status')->orWhere('live_status', '!=', 'live'))
            ->whereRaw("REGEXP_REPLACE(visitor_phone, '[^0-9]', '') = ?", [$digits])
            ->get()->each(function ($old) {
                $old->update([
                    'status' => 'closed', 'ai_active' => false, 'closed_at' => $old->closed_at ?? now(),
                    'live_status' => $old->live_status === 'waiting' ? 'ended' : $old->live_status,   // a 'missed' callback stays on the staff queue
                ]);
                $this->sysMsg($old, 'Visitor started a new chat — this one was closed.');
            });
    }

    private function findConvo(ChatWidget $widget, ?string $token): ?WidgetConversation
    {
        return $token ? WidgetConversation::where('chat_widget_id', $widget->id)->where('session_token', $token)->first() : null;
    }

    private function sysMsg(WidgetConversation $convo, string $text): void
    {
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'system', 'text' => $text]);
    }

    /** The one-line embed: a self-contained loader that renders the chat bubble + panel. */
    public function script(string $key): Response
    {
        $apiBase = url('/api/v1/public/widget');
        if (!in_array(parse_url($apiBase, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
            $apiBase = preg_replace('#^http://#', 'https://', $apiBase);
        }
        $js = view('widget.loader', ['key' => $key, 'apiBase' => $apiBase])->render();

        return response($js, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function widget(string $key): ?ChatWidget
    {
        return ChatWidget::with('company')->where('public_key', $key)->first();
    }
}
