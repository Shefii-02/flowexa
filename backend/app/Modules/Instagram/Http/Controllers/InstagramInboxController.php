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
        $threads = InstagramConversation::where('company_id', auth()->user()->company_id)
            ->when($request->account_id, fn ($q, $id) => $q->where('instagram_account_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
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
            'within_window' => $convo->withinMessagingWindow(),
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:900']])['text'];
        $convo = $this->find($id);

        // A human reply carries the HUMAN_AGENT tag → allowed for up to 7 days.
        $msg = $this->conversations->send($convo, $text, 'manual', humanAgent: true);

        if ($msg && $msg->status === 'failed') {
            return response()->json(['message' => $msg->error ?? 'Send failed', 'sent_message' => $msg], 422);
        }
        return response()->json(['message' => 'Sent.', 'sent_message' => $msg]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'status'     => ['sometimes', Rule::in(['open', 'snoozed', 'closed'])],
            'ai_enabled' => ['sometimes', 'boolean'],
        ]);
        $convo = $this->find($id);
        $convo->update($d);
        return response()->json(['conversation' => $convo->fresh()]);
    }

    private function find(int $id): InstagramConversation
    {
        return InstagramConversation::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
