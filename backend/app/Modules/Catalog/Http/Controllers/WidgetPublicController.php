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

        // A closed conversation (resolved, or handed to staff) restarts cleanly on the next message.
        if ($convo->status === 'closed') {
            $convo->update(['status' => 'active', 'flow_node_id' => null, 'flow_state' => null, 'live_status' => null]);
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
        $reply = $flowTurn['reply'] ?? $this->agent->reply($widget, $convo, $message);

        return response()->json([
            'session_token' => $convo->session_token,
            'reply'         => $reply,
            'options'       => $flowTurn['options'] ?? [],
            'controls'      => $flowTurn['controls'] ?? [],
            'closed'        => $flowTurn['closed'] ?? false,
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
        } else {
            $convo->update($details + [
                'status' => 'active', 'live_status' => 'waiting', 'live_requested_at' => now(),
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
                $this->sysMsg($convo, 'No staff member was available in time.');
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
        $this->sysMsg($convo, "Thank you {$convo->visitor_name}. Our staff will contact you on {$convo->visitor_phone}.");
        $this->alerts->notifyLiveRequest($widget, $convo->fresh(), true);

        return response()->json($this->liveState($widget, $convo->fresh(), (int) $request->input('after', 0)));
    }

    /** Visitor sends a photo / voice note / file (max 2 MB) to the staff member in a live chat. */
    public function liveUpload(Request $request, string $key): JsonResponse
    {
        $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'file'          => ['required', 'file'],
            'text'          => ['nullable', 'string', 'max:500'],
            'reply_to_id'   => ['nullable', 'integer'],
        ]);
        $widget = $this->liveWidget($request, $key);
        if ($widget instanceof JsonResponse) return $widget;
        $convo = $this->findConvo($widget, $request->input('session_token'));
        if (!$convo || !in_array($convo->live_status, ['waiting', 'live', 'missed'], true)) {
            return response()->json(['message' => 'Start a live chat first.'], 422);
        }

        $cols = \App\Modules\Catalog\Support\LiveChatSupport::storeUpload($request->file('file'), $convo, ['image', 'file']); // visitors: photos & files only (voice is staff-only)
        $msg = WidgetMessage::create(array_merge([
            'widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => (string) $request->input('text', ''),
            'reply_to_id' => $this->quotable($convo, $request->input('reply_to_id')),
        ], $cols));
        $convo->update(['last_message_at' => now()]);

        return response()->json(['message' => \App\Modules\Catalog\Support\LiveChatSupport::format($msg)]);
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
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function widget(string $key): ?ChatWidget
    {
        return ChatWidget::with('company')->where('public_key', $key)->first();
    }
}
