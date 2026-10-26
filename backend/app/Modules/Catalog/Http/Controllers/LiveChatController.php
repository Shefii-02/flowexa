<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WidgetChatStaff;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Illuminate\Http\JsonResponse;
use App\Modules\Catalog\Support\LiveChatSupport as Live;
use Illuminate\Http\Request;

/** Staff side of website live chat: see waiting requests, accept one, reply, end. Polled by the portal. */
class LiveChatController extends Controller
{
    private const CLOSED = "(widget_conversations.status = 'closed' OR widget_conversations.live_status = 'ended')";
    private const UNREAD = "EXISTS (SELECT 1 FROM widget_messages m WHERE m.widget_conversation_id = widget_conversations.id
                              AND m.role = 'visitor' AND (widget_conversations.staff_read_at IS NULL OR m.created_at > widget_conversations.staff_read_at))";

    /** Staff inbox: ?tab=unread|read|closed, optional ?q= (name / phone). Includes per-tab counts. */
    public function inbox(Request $request): JsonResponse
    {
        $base = fn () => WidgetConversation::where('company_id', auth()->user()->company_id)
            ->whereHas('messages')
            ->when($request->query('widget_id'), fn ($q, $w) => $q->where('chat_widget_id', $w))
            ->when($request->query('q'), function ($q, $s) {
                $digits = preg_replace('/\D+/', '', $s);
                return $q->where(function ($w) use ($s, $digits) {
                    $w->where('visitor_name', 'like', "%{$s}%")->orWhere('visitor_phone', 'like', "%{$s}%");
                    // "98 4 7…" / "+91-98…" still finds the number whatever the stored formatting
                    if (strlen($digits) >= 3) $w->orWhereRaw("REGEXP_REPLACE(visitor_phone, '[^0-9]', '') LIKE ?", ["%{$digits}%"]);
                });
            });

        $scope = fn ($q, string $tab) => match ($tab) {
            'closed' => $q->whereRaw(self::CLOSED),
            'read'   => $q->whereRaw('NOT ' . self::CLOSED)->whereRaw('NOT ' . self::UNREAD),
            default  => $q->whereRaw('NOT ' . self::CLOSED)->whereRaw(self::UNREAD),
        };

        $tab = in_array($request->query('tab'), ['unread', 'read', 'closed'], true) ? $request->query('tab') : 'unread';

        $rows = $scope($base(), $tab)
            ->with('widget:id,name')
            ->addSelect(['last_text' => \App\Models\WidgetMessage::selectRaw(
                "CASE WHEN text <> '' THEN text WHEN type = 'image' THEN '📷 Photo' WHEN type = 'audio' THEN '🎤 Voice message' ELSE '📎 File' END")
                ->whereColumn('widget_conversation_id', 'widget_conversations.id')->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')->orderByDesc('id')     // latest first; the widget groups the same visitor together
            ->paginate(100, ['widget_conversations.*']);

        return response()->json(array_merge($rows->toArray(), [
            'counts' => [
                'unread' => $scope($base(), 'unread')->count(),
                'read'   => $scope($base(), 'read')->count(),
                'closed' => $scope($base(), 'closed')->count(),
            ],
        ]));
    }

    /** Staff mark a chat as "visitor not satisfied" so an admin can study it. */
    public function flag(int $id): JsonResponse
    {
        $this->find($id)->flag('staff_flag');
        return response()->json(['flagged' => true]);
    }

    /** Admin / higher staff: chats marked not satisfied. ?reviewed=0|1 */
    public function review(Request $request): JsonResponse
    {
        $rows = WidgetConversation::where('company_id', auth()->user()->company_id)->whereNotNull('flagged_at')
            ->where($request->boolean('reviewed') ? fn ($q) => $q->whereNotNull('reviewed_at') : fn ($q) => $q->whereNull('reviewed_at'))
            ->with(['widget:id,name', 'attendedBy:id,name'])
            ->orderByDesc('flagged_at')
            ->paginate(30, ['id', 'chat_widget_id', 'visitor_name', 'visitor_phone', 'case_status', 'flag_reason', 'flagged_at',
                'attended_by', 'staff_name', 'reviewed_at', 'reviewed_by', 'review_note', 'collected', 'last_message_at']);
        return response()->json($rows);
    }

    public function markReviewed(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->find($id)->update(['reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'review_note' => $data['note'] ?? null]);
        return response()->json(['reviewed' => true]);
    }

    /** Bot vs staff outcomes, handoff and satisfaction numbers, per-staff workload. ?days=30 */
    public function analytics(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->query('days', 30)));
        $base = fn () => WidgetConversation::where('company_id', auth()->user()->company_id)
            ->where('created_at', '>=', now()->subDays($days))->whereNull('duplicate_of_id');

        $total   = $base()->whereHas('messages')->count();
        $by      = $base()->whereHas('messages')->selectRaw('case_status, COUNT(*) c')->groupBy('case_status')->pluck('c', 'case_status');
        $handoff = $base()->whereNotNull('live_requested_at')->count();
        $flags   = $base()->whereNotNull('flagged_at')->selectRaw('flag_reason, COUNT(*) c')->groupBy('flag_reason')->pluck('c', 'flag_reason');
        $repeats = WidgetConversation::where('company_id', auth()->user()->company_id)
            ->where('created_at', '>=', now()->subDays($days))->whereNotNull('duplicate_of_id')->count();

        $staff = $base()->whereNotNull('attended_by')
            ->selectRaw("attended_by, MAX(staff_name) name, COUNT(*) attended,
                SUM(case_status = 'closed') closed,
                AVG(TIMESTAMPDIFF(SECOND, live_requested_at, attended_at)) avg_wait_seconds")
            ->groupBy('attended_by')->orderByDesc('attended')->get();

        $issues = $base()->whereNotNull('flagged_at')->latest('flagged_at')->limit(10)->get(['id', 'visitor_name', 'flag_reason', 'collected'])
            ->map(fn ($c) => ['id' => $c->id, 'visitor' => $c->visitor_name, 'reason' => $c->flag_reason,
                'issue' => $c->collected['unresolved_issue'] ?? null]);

        return response()->json([
            'days' => $days, 'total' => $total,
            'solved_by_bot'  => (int) ($by['closed'] ?? 0) - $base()->where('case_status', 'closed')->whereNotNull('attended_by')->count(),
            'status'         => ['open' => (int) ($by['open'] ?? 0), 'closed' => (int) ($by['closed'] ?? 0), 'pending' => (int) ($by['pending'] ?? 0),
                                 'unread' => (int) ($by['unread'] ?? 0), 'attended' => (int) ($by['attended'] ?? 0)],
            'handed_to_staff' => $handoff, 'repeat_requests_merged' => $repeats,
            'not_satisfied'  => $flags, 'awaiting_review' => $base()->whereNotNull('flagged_at')->whereNull('reviewed_at')->count(),
            'staff'          => $staff, 'recent_flagged' => $issues,
        ]);
    }

    public function markRead(int $id): JsonResponse
    {
        $this->find($id)->update(['staff_read_at' => now()]);
        return response()->json(['read' => true]);
    }
    public function queue(): JsonResponse
    {
        $rows = WidgetConversation::where('company_id', auth()->user()->company_id)
            ->where(fn ($q) => $q->whereIn('live_status', ['waiting', 'live'])
                ->orWhere(fn ($m) => $m->where('live_status', 'missed')->where('live_requested_at', '>', now()->subDay())))
            ->with('widget:id,name')
            ->orderByRaw("FIELD(live_status, 'waiting', 'live', 'missed')")
            ->orderBy('live_requested_at')
            ->get(['id', 'chat_widget_id', 'visitor_name', 'visitor_phone', 'live_status', 'live_requested_at',
                'assigned_user_id', 'staff_name', 'callback_confirmed_at', 'page_url', 'last_message_at']);

        return response()->json(['conversations' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $convo = $this->find($id);
        $convo->forceFill(['staff_read_at' => now()])->saveQuietly();
        return response()->json([
            'conversation' => $convo->only(['id', 'visitor_name', 'visitor_phone', 'status', 'case_status', 'flag_reason', 'flagged_at', 'review_note', 'reviewed_at', 'live_status', 'staff_name', 'assigned_user_id', 'page_url', 'callback_confirmed_at', 'contact_id', 'lead_id']),
            'messages'     => $convo->messages()->where('id', '>', (int) $request->query('after', 0))->get()->map(fn ($m) => Live::format($m))->values(),
        ]);
    }

    public function accept(int $id): JsonResponse
    {
        $convo = $this->find($id);
        $user  = auth()->user();

        $limit = Live::limit($convo->widget);
        if (Live::handling($user->id) >= $limit) {
            return response()->json(['message' => "You are already handling {$limit} chats — end one before accepting another."], 409);
        }

        // Atomic: only one staff member can win a waiting / missed request.
        $won = WidgetConversation::where('id', $convo->id)->whereIn('live_status', ['waiting', 'missed'])
            ->update(['live_status' => 'live', 'ai_active' => false, 'live_accepted_at' => now(), 'assigned_user_id' => $user->id, 'staff_name' => $user->name,
                'case_status' => 'attended', 'attended_by' => $user->id, 'attended_at' => now()]);
        if (!$won) {
            return response()->json(['message' => $convo->live_status === 'live' ? 'Another staff member already accepted this chat.' : 'This chat is no longer waiting.'], 409);
        }

        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'system', 'text' => "{$user->name} joined the chat."]);
        return $this->show(request(), $id);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data  = $request->validate([
            'text'        => ['nullable', 'string', 'max:2000', 'required_without:file'],
            'file'        => ['nullable', 'file'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);
        $convo = $this->find($id);
        abort_unless($convo->live_status === 'live' && $convo->assigned_user_id === auth()->id(), 403, 'Accept the chat first.');

        $cols = $request->hasFile('file') ? Live::storeUpload($request->file('file'), $convo) : [];
        $replyTo = !empty($data['reply_to_id'])
            ? \App\Models\WidgetMessage::where('widget_conversation_id', $convo->id)->where('id', $data['reply_to_id'])->value('id') : null;

        $msg = WidgetMessage::create(array_merge([
            'widget_conversation_id' => $convo->id, 'role' => 'staff', 'text' => (string) ($data['text'] ?? ''), 'reply_to_id' => $replyTo,
        ], $cols));
        $convo->update(['last_message_at' => now()]);
        return response()->json(['message' => Live::format($msg)]);
    }

    /** The signed-in staff member's own alert preference + how full their chat slots are. */
    public function me(): JsonResponse
    {
        $u = auth()->user();
        $widgetLimit = (int) (\App\Models\ChatWidget::where('company_id', $u->company_id)->max('live_max_per_staff') ?: 5);
        return response()->json([
            'muted' => Live::isMuted($u->id), 'handling' => Live::handling($u->id), 'limit' => $widgetLimit,
        ]);
    }

    public function setMuted(Request $request): JsonResponse
    {
        $data = $request->validate(['muted' => ['required', 'boolean']]);
        $u = auth()->user();
        WidgetChatStaff::updateOrCreate(['user_id' => $u->id], ['company_id' => $u->company_id, 'muted' => $data['muted']]);
        return response()->json(['muted' => (bool) $data['muted']]);
    }

    /** Permanently delete a conversation, its messages and every uploaded file. */
    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['deleted' => true]);
    }

    public function end(int $id): JsonResponse
    {
        $convo = $this->find($id);
        $convo->update(['live_status' => 'ended', 'status' => 'closed', 'case_status' => 'closed', 'closed_at' => now()]);
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'system', 'text' => 'This conversation is now closed. Thank you!']);
        return response()->json(['ended' => true]);
    }

    private function find(int $id): WidgetConversation
    {
        return WidgetConversation::where('company_id', auth()->user()->company_id)->findOrFail($id);
    }
}
