<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ChatWidget;
use App\Models\WidgetConversation;
use App\Modules\Catalog\WidgetChatAgent;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Str;

/**
 * Public, unauthenticated endpoints the embedded widget calls from a customer's website.
 * Origin is checked per-widget; abuse is bounded by a throttle on the routes.
 */
class WidgetPublicController extends Controller
{
    public function __construct(private readonly WidgetChatAgent $agent) {}

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
            'branding'   => $widget->branding ?: (object) [],
        ]);
    }

    /** One visitor turn. Creates the conversation on the first call. */
    public function chat(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
            'message'       => ['required', 'string', 'max:2000'],
            'page_url'      => ['nullable', 'string', 'max:500'],
        ]);

        $widget = $this->widget($key);
        if (!$widget || !$widget->is_active) {
            return response()->json(['message' => 'Widget not found'], 404);
        }
        if (!$widget->originAllowed($request->headers->get('Origin'))) {
            return response()->json(['message' => 'Origin not allowed'], 403);
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

        $reply = $this->agent->reply($widget, $convo, $data['message']);

        return response()->json([
            'session_token' => $convo->session_token,
            'reply'         => $reply,
            'status'        => $convo->fresh()->status,
        ]);
    }

    /** The one-line embed: a self-contained loader that renders the chat bubble + panel. */
    public function script(string $key): Response
    {
        $apiBase = url('/api/v1/public/widget');
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
