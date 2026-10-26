<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ChatWidget;
use App\Models\WidgetConversation;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class WidgetController extends Controller
{
    public function index(): JsonResponse
    {
        $widgets = ChatWidget::where('company_id', auth()->user()->company_id)->get();
        return response()->json([
            'widgets'   => $widgets->map(fn ($w) => $this->present($w)),
            'ai_configured' => $this->aiConfigured(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->withAiGuard($this->validated($request));

        $w = ChatWidget::create(array_merge($data, [
            'company_id' => auth()->user()->company_id,
        ]));
        return response()->json(['message' => 'Widget created.', 'widget' => $this->present($w)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $w = $this->find($id);
        $w->update($this->withAiGuard($this->validated($request, false)));
        return response()->json(['message' => 'Widget updated.', 'widget' => $this->present($w->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Widget deleted.']);
    }

    public function conversations(Request $request, int $id): JsonResponse
    {
        $this->find($id);
        $rows = WidgetConversation::where('chat_widget_id', $id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->with(['lead:id,stage'])
            ->orderByDesc('last_message_at')
            ->paginate(30);
        return response()->json($rows);
    }

    public function conversation(int $id, int $conversationId): JsonResponse
    {
        $this->find($id);
        $convo = WidgetConversation::where('chat_widget_id', $id)->where('id', $conversationId)->firstOrFail();
        return response()->json([
            'conversation' => $convo->load('lead:id,stage,assigned_to'),
            'messages'     => $convo->messages()->get(),
        ]);
    }

    // ── helpers ──

    /** True when the company has an AI provider key set up (own key or platform fallback). */
    private function aiConfigured(): bool
    {
        return app(\App\Modules\WaChat\Services\Rag\LlmClient::class)->isConfigured(auth()->user()->company);
    }

    /** The AI fallback can't be switched on until AI is set up. */
    private function withAiGuard(array $data): array
    {
        if (!empty($data['ai_fallback_enabled']) && !$this->aiConfigured()) {
            $data['ai_fallback_enabled'] = false;
        }
        return $data;
    }

    private function present(ChatWidget $w): array
    {
        return array_merge($w->toArray(), [
            'embed_snippet' => $this->snippet($w),
            'script_url'    => $this->secureUrl("/api/v1/public/widget/{$w->public_key}.js"),
        ]);
    }

    /** Always https for real hosts so the embed never trips mixed-content on https sites. */
    private function secureUrl(string $path): string
    {
        $u = url($path);
        $host = parse_url($u, PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1'], true) ? $u : preg_replace('#^http://#', 'https://', $u);
    }

    private function snippet(ChatWidget $w): string
    {
        $url = $this->secureUrl("/api/v1/public/widget/{$w->public_key}.js");
        return "<script async src=\"{$url}\"></script>";
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'name'               => [$req, 'string', 'max:120'],
            'is_active'          => ['sometimes', 'boolean'],
            'agent_name'         => ['sometimes', 'string', 'max:80'],
            'greeting'           => ['sometimes', 'string', 'max:500'],
            'branding'           => ['sometimes', 'nullable', 'array'],
            'branding.primary_color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'branding.position'  => ['sometimes', 'nullable', Rule::in(['right', 'left'])],
            'branding.launcher_text' => ['sometimes', 'nullable', 'string', 'max:40'],
            'allowed_origins'    => ['sometimes', 'nullable', 'array'],
            'allowed_origins.*'  => ['string', 'max:200'],
            'notify_emails'      => ['sometimes', 'nullable', 'array'],
            'notify_emails.*'    => ['email'],
            'notify_whatsapp'    => ['sometimes', 'nullable', 'array'],
            'notify_whatsapp.*'  => ['string', 'max:20'],
            'live_chat_enabled'  => ['sometimes', 'boolean'],
            'live_max_per_staff' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'live_wait_seconds'  => ['sometimes', 'integer', 'min:15', 'max:900'],
            'ai_fallback_enabled' => ['sometimes', 'boolean'],
            'wa_session_id'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'flow_builder_id'    => ['sometimes', 'nullable', 'integer', Rule::exists('flow_builders', 'id')->where('company_id', auth()->user()->company_id)],
        ]);
    }

    private function find(int $id): ChatWidget
    {
        return ChatWidget::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
