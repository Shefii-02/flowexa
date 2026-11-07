<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramConversation;
use App\Modules\Instagram\Services\InstagramConversationService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class InstagramInboxController extends Controller
{
    public function __construct(private readonly InstagramConversationService $conversations) {}

    public function index(Request $request): JsonResponse
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');

        $threads = InstagramConversation::where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('instagram_account_id', $allowed))
            ->when($request->account_id, fn ($q, $id) => $q->where('instagram_account_id', $id))
            ->whereHas('account', fn ($q) => $q->where('channel', $request->query('channel', 'instagram')))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('needs_agent'), fn ($q) => $q->where('needs_agent', true))
            ->with('account:id,username')
            ->orderByDesc('last_message_at')
            ->paginate(30);
        return response()->json($threads);
    }

    public function show(int $id): JsonResponse
    {
        $convo = $this->find($id);
        $convo->update(['unread_count' => 0]);
        return response()->json([
            'conversation' => $convo->load('account:id,username,ai_enabled'),
            'messages'     => $convo->messages()->get(),
            'flow_runs'    => \App\Models\InstagramFlowRun::where('instagram_conversation_id', $convo->id)
                ->with('flow:id,name')->latest('id')->limit(5)
                ->get(['id', 'instagram_flow_id', 'status', 'current_node', 'wait_kind', 'error', 'created_at']),
            'within_window' => $convo->withinMessagingWindow(),
            'human_agent_enabled' => (bool) config('services.instagram.human_agent_enabled'),
            'reactions' => \App\Modules\Instagram\Services\InstagramConversationService::reactionsFor($convo->loadMissing('account')),
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'text'              => ['nullable', 'string', 'max:900'],
            'attachment_type'   => ['nullable', 'required_with:attachment_url', Rule::in(['image', 'video', 'audio', 'file'])],
            'attachment_url'    => ['nullable', 'required_with:attachment_type', 'url:https', 'max:2000'],
            'reply_to_message_id' => ['nullable', 'integer'],
        ]);
        $text = trim((string) ($d['text'] ?? ''));
        if ($text === '' && empty($d['attachment_url'])) {
            return response()->json(['message' => 'Write a message or attach a file.'], 422);
        }
        $convo = $this->find($id);

        // Quoted reply: the message must belong to this thread and carry an Instagram id.
        $replyMid = null;
        if (!empty($d['reply_to_message_id'])) {
            $replyMid = $convo->messages()->whereKey($d['reply_to_message_id'])->value('ig_message_id');
        }

        // Staff reply: plain message inside 24h; HUMAN_AGENT tag (7 days) only when Meta has approved it.
        // An attachment goes first (as its own message); the text, if any, follows it.
        $sent = [];
        if (!empty($d['attachment_url'])) {
            $sent[] = $this->conversations->sendMedia($convo, $d['attachment_type'], $d['attachment_url'], 'manual', humanAgent: true, replyToMid: $replyMid);
        }
        if ($text !== '') {
            $sent[] = $this->conversations->send($convo, $text, 'manual', humanAgent: true, replyToMid: $sent ? null : $replyMid);
        }

        $failed = collect($sent)->first(fn ($m) => $m && $m->status === 'failed');
        if ($failed) {
            return response()->json(['message' => $failed->error ?? 'Send failed', 'sent_message' => $failed, 'sent_messages' => $sent], 422);
        }
        return response()->json(['message' => 'Sent.', 'sent_message' => end($sent), 'sent_messages' => $sent]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'status'     => ['sometimes', Rule::in(['open', 'snoozed', 'closed'])],
            'ai_enabled' => ['sometimes', 'boolean'],
            'needs_agent' => ['sometimes', 'boolean'],
        ]);
        $convo = $this->find($id);
        $convo->update($d);
        return response()->json(['conversation' => $convo->fresh()]);
    }

    /** POST conversations/{id}/messages/{messageId}/react — { reaction: "love" | null } */
    public function react(Request $request, int $id, int $messageId): JsonResponse
    {
        $reaction = $request->validate(['reaction' => ['nullable', 'string', 'max:20']])['reaction'] ?? null;
        $convo = $this->find($id);
        $msg = $convo->messages()->findOrFail($messageId);
        try {
            $msg = $this->conversations->react($convo, $msg, $reaction);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not react: ' . $e->getMessage()], 422);
        }
        return response()->json(['message' => $reaction ? 'Reacted.' : 'Reaction removed.', 'sent_message' => $msg]);
    }

    private function find(int $id): InstagramConversation
    {
        return InstagramConversation::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
