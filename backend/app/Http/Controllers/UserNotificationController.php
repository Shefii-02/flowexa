<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The signed-in person's own notification inbox (bell). Everything is scoped to them — nobody sees another account's notifications. */
class UserNotificationController extends Controller
{
    private function mine()
    {
        return UserNotification::where('user_id', auth()->id());
    }

    /** Unread @mentions-and-messages across the person's chats (not muted) — added to the bell's badge. */
    private function teamUnread(): int
    {
        $uid = (int) auth()->id();
        try {
            return (int) DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at')
                    ->where(fn ($mm) => $mm->where('m.muted', false)->orWhere(fn ($t) => $t->whereNotNull('m.muted_until')->where('m.muted_until', '<=', now())));
            })->whereNull('msg.deleted_at')->whereColumn('msg.id', '>', 'm.last_read_message_id')
                ->where(fn ($x) => $x->whereNull('msg.sender_id')->orWhere('msg.sender_id', '!=', $uid))->count();
        } catch (\Throwable) {
            return 0;   // team chat not installed for this company
        }
    }

    /** GET /notifications?category=lead|other|team|all&unread=1&per_page= */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['category' => ['nullable', 'in:all,lead,other,team,hr,campaign,billing,system'], 'unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        $cat = $d['category'] ?? 'all';
        $q = $this->mine()->when($cat === 'other', fn ($x) => $x->where('category', '!=', 'lead'))
            ->when(!in_array($cat, ['all', 'other'], true), fn ($x) => $x->where('category', $cat))
            ->when(!empty($d['unread']), fn ($x) => $x->whereNull('read_at'))->orderByDesc('id');
        $page = $q->paginate((int) ($d['per_page'] ?? 30));
        $page->getCollection()->transform(fn (UserNotification $n) => [
            'id' => $n->id, 'type' => $n->type, 'category' => $n->category, 'title' => $n->title, 'message' => $n->body, 'data' => $n->data,
            'created_at' => $n->created_at?->toIso8601String(), 'read_at' => $n->read_at?->toIso8601String(),
        ]);
        return response()->json($page->toArray() + $this->counts());
    }

    /** GET /notifications/unread-count — cheap, for the bell badge */
    public function unreadCount(): JsonResponse
    {
        return response()->json($this->counts());
    }

    private function counts(): array
    {
        $by = $this->mine()->whereNull('read_at')->selectRaw("category = 'lead' as is_lead, count(*) c")->groupBy('is_lead')->pluck('c', 'is_lead');
        $lead = (int) ($by[1] ?? 0); $other = (int) ($by[0] ?? 0);
        $team = $this->teamUnread();
        return ['unread' => $lead + $other, 'unread_leads' => $lead, 'unread_other' => $other, 'team_chat_unread' => $team, 'badge' => $lead + $other + $team];
    }

    /** POST /notifications/clear { ids?: [], category?: all|lead|other|… } — remove notifications (just these ids, else everything in that tab). Only your own. */
    public function clear(Request $r): JsonResponse
    {
        $d = $r->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer'], 'category' => ['nullable', 'in:all,lead,other,team,hr,campaign,billing,system']]);
        $q = $this->mine();
        if (!empty($d['ids'])) {
            $q->whereIn('id', $d['ids']);
        } else {
            $cat = $d['category'] ?? 'all';
            $q->when($cat === 'other', fn ($x) => $x->where('category', '!=', 'lead'))->when(!in_array($cat, ['all', 'other'], true), fn ($x) => $x->where('category', $cat));
        }
        $n = $q->delete();
        return response()->json(['message' => $n === 1 ? '1 notification cleared.' : "{$n} notifications cleared.", 'cleared' => $n] + $this->counts());
    }

    /** POST /notifications/read { ids?: [] } — no ids = mark everything read */
    public function read(Request $r): JsonResponse
    {
        $ids = array_filter((array) $r->input('ids', []), 'is_numeric');
        $this->mine()->whereNull('read_at')->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);
        return response()->json(['message' => 'Marked read.'] + $this->counts());
    }
}
