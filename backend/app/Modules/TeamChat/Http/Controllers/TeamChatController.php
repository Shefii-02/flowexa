<?php

namespace App\Modules\TeamChat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\StaffRealtime;

/**
 * Internal team chat. Every chat (direct or group) supports: text, reply, attachments, edit, delete,
 * reactions, @mentions, read receipts ("seen by"), unread counts and mute. Groups add admins, rename,
 * add / remove members, leave, "only admins can post" and delete-group.
 * The mobile app polls (list ~10s, open chat ~3s) — no websocket needed.
 */
class TeamChatController extends Controller
{
    private function cid(): int { return (int) auth()->user()->company_id; }
    private function uid(): int { return (int) auth()->id(); }

    /** The caller's active membership row, or 403. */
    private function member(int $chatId): object
    {
        $m = DB::table('team_chat_members as m')->join('team_chats as c', 'c.id', '=', 'm.chat_id')
            ->where('m.chat_id', $chatId)->where('m.user_id', $this->uid())->whereNull('m.left_at')
            ->where('c.company_id', $this->cid())->select('m.*', 'c.type', 'c.only_admins_post', 'c.only_admins_edit', 'c.only_admins_add', 'c.name as chat_name')->first();
        abort_unless($m, 403, 'You are not a member of this chat.');
        return $m;
    }

    private function adminOnly(object $m): void
    {
        abort_unless($m->type === 'group' && $m->role === 'admin', 403, 'Only group admins can do this.');
    }

    private function system(int $chatId, string $text): void
    {
        $id = DB::table('team_chat_messages')->insertGetId([
            'chat_id' => $chatId, 'company_id' => $this->cid(), 'sender_id' => null, 'type' => 'system',
            'body' => $text, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('team_chats')->where('id', $chatId)->update(['last_message_id' => $id, 'last_message_at' => now()]);
        $this->emit($chatId, 'system', ['message_id' => $id]);
    }

    /** Record per-message delivered / read times for everything between the old and new marker. */
    private function recordReceipts(int $chatId, int $userId, int $fromExclusive, int $toInclusive, bool $read): void
    {
        if ($toInclusive <= $fromExclusive) { return; }
        DB::statement(
            "INSERT INTO team_chat_receipts (message_id, user_id, delivered_at, read_at)
             SELECT id, ?, NOW(), " . ($read ? 'NOW()' : 'NULL') . " FROM team_chat_messages
              WHERE chat_id = ? AND id > ? AND id <= ? AND type <> 'system' AND (sender_id IS NULL OR sender_id <> ?)
             ON DUPLICATE KEY UPDATE delivered_at = COALESCE(delivered_at, VALUES(delivered_at))" . ($read ? ', read_at = COALESCE(read_at, VALUES(read_at))' : ''),
            [$userId, $chatId, $fromExclusive, $toInclusive, $userId]);
    }

    /** Excludes messages the caller deleted "for me". */
    private function notHidden($q, string $col = 'id')
    {
        return $q->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('team_chat_hides as h')->whereColumn('h.message_id', $col)->where('h.user_id', $this->uid()));
    }

    private function mutedNow(object $row): bool
    {
        return (bool) $row->muted && (!$row->muted_until || strtotime((string) $row->muted_until) > time());
    }

    /** Company moderators: the owner and anyone who can manage staff. */
    private function isModerator(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->hasPermission('staff.manage');
    }

    private function moderatorOnly(): void { abort_unless($this->isModerator(), 403, 'Only company admins can do this.'); }

    private function name(int $userId): string { return (string) DB::table('users')->where('id', $userId)->value('name'); }

    /** Tell every active member's socket something changed in this chat (clients re-fetch / patch). */
    private function emit(int $chatId, string $kind, array $extra = []): void
    {
        $ids = DB::table('team_chat_members')->where('chat_id', $chatId)->whereNull('left_at')->pluck('user_id');
        foreach ($ids as $u) {
            StaffRealtime::toStaff((int) $u, 'team_chat', ['chat_id' => $chatId, 'kind' => $kind, 'by' => $this->uid()] + $extra);
        }
    }

    /** Push for a new message: mentioned users always (even if muted), everyone else not muted. */
    private function push(int $chatId, int $mid, string $preview, array $mentions): void
    {
        \App\Modules\TeamChat\Support\TeamChatPush::send($chatId, $mid, $this->uid(), $mentions);
    }

    // ── People ───────────────────────────────────────────────────────────────

    /** GET /team-chat/users — everyone in the company the caller can start a chat with. */
    public function users(): JsonResponse
    {
        return response()->json(['data' => DB::table('users')->where('company_id', $this->cid())->where('is_active', true)
            ->where('id', '!=', $this->uid())->orderBy('name')->get(['id', 'name', 'avatar', 'department'])]);
    }

    // ── Chats ────────────────────────────────────────────────────────────────

    /** GET /team-chat/chats */
    public function chats(Request $r): JsonResponse
    {
        $uid = $this->uid();
        $perPage = min(max((int) $r->query('per_page', 30), 5), 100);
        $page = max((int) $r->query('page', 1), 1);
        $q = trim((string) $r->query('q', ''));

        $base = DB::table('team_chat_members as m')->join('team_chats as c', 'c.id', '=', 'm.chat_id')
            ->where('m.user_id', $uid)->whereNull('m.left_at')->where('c.company_id', $this->cid());
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            // group name, or — for a direct chat — the other person's name
            $base->where(fn ($w) => $w->where('c.name', 'like', $like)->orWhereExists(fn ($e) => $e->select(DB::raw(1))
                ->from('team_chat_members as om')->join('users as ou', 'ou.id', '=', 'om.user_id')
                ->whereColumn('om.chat_id', 'c.id')->where('om.user_id', '!=', $uid)->where('c.type', 'direct')->where('ou.name', 'like', $like)));
        }
        if ($r->query('type') === 'direct' || $r->query('type') === 'group') { $base->where('c.type', $r->query('type')); }
        // Unread-only: chats that have at least one message from someone else after my read marker.
        $unreadExists = fn ($e) => $e->select(DB::raw(1))->from('team_chat_messages as um')->whereColumn('um.chat_id', 'c.id')->whereNull('um.deleted_at')
            ->whereColumn('um.id', '>', 'm.last_read_message_id')->where(fn ($x) => $x->whereNull('um.sender_id')->orWhere('um.sender_id', '!=', $uid));
        if ($r->query('filter') === 'unread') { $base->whereExists($unreadExists); }

        // One extra row tells us whether another page exists without a COUNT(*).
        $fetched = (clone $base)->orderByRaw('COALESCE(c.last_message_at, c.created_at) DESC')->orderByDesc('c.id')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)
            ->get(['c.*', 'm.last_read_message_id', 'm.muted', 'm.muted_until', 'm.role as my_role']);
        $hasMore = $fetched->count() > $perPage;
        $rows = $fetched->take($perPage)->values();

        // Total unread across ALL my chats (not just this page) — drives the tab badge.
        $unreadTotal = (int) DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at')->where(fn ($mm) => $mm->where('m.muted', false)->orWhere(fn ($t) => $t->whereNotNull('m.muted_until')->where('m.muted_until', '<=', now())));
            })->join('team_chats as c', 'c.id', '=', 'msg.chat_id')->where('c.company_id', $this->cid())->whereNull('msg.deleted_at')
            ->whereColumn('msg.id', '>', 'm.last_read_message_id')->where(fn ($x) => $x->whereNull('msg.sender_id')->orWhere('msg.sender_id', '!=', $uid))->count();

        // Tab badges across ALL my chats (not only this page): unread conversations per kind + unread @mentions.
        $perChat = DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at');
            })->join('team_chats as c', 'c.id', '=', 'msg.chat_id')->where('c.company_id', $this->cid())->whereNull('msg.deleted_at')
            ->whereColumn('msg.id', '>', 'm.last_read_message_id')->where(fn ($x) => $x->whereNull('msg.sender_id')->orWhere('msg.sender_id', '!=', $uid))
            ->groupBy('c.id', 'c.type')->select('c.id', 'c.type', DB::raw('count(*) as n'))->get();
        $mentionsTotal = (int) DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at');
            })->join('team_chats as c', 'c.id', '=', 'msg.chat_id')->where('c.company_id', $this->cid())->whereNull('msg.deleted_at')
            ->whereColumn('msg.id', '>', 'm.last_read_message_id')->whereRaw('JSON_CONTAINS(msg.mentions, CAST(? AS JSON))', [(string) $uid])->count();
        $tabCounts = ['unread_chats' => $perChat->count(), 'direct_unread' => $perChat->where('type', 'direct')->count(),
                   'group_unread' => $perChat->where('type', 'group')->count(), 'mentions' => $mentionsTotal];

        $ids = $rows->pluck('id');
        $unread = DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid);
            })->whereIn('msg.chat_id', $ids)->whereNull('msg.deleted_at')
            ->whereColumn('msg.id', '>', 'm.last_read_message_id')->where(fn ($q) => $q->whereNull('msg.sender_id')->orWhere('msg.sender_id', '!=', $uid))
            ->groupBy('msg.chat_id')->select('msg.chat_id', DB::raw('count(*) as c'))->pluck('c', 'chat_id');
        $mentionUnread = DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid);
            })->whereIn('msg.chat_id', $ids)->whereNull('msg.deleted_at')->whereColumn('msg.id', '>', 'm.last_read_message_id')
            ->whereRaw('JSON_CONTAINS(msg.mentions, CAST(? AS JSON))', [(string) $uid])
            ->groupBy('msg.chat_id')->select('msg.chat_id', DB::raw('count(*) as c'))->pluck('c', 'chat_id');
        $last = DB::table('team_chat_messages')->whereIn('id', $rows->pluck('last_message_id')->filter())->get()->keyBy('id');
        $lastSenders = DB::table('users')->whereIn('id', $last->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $counts = DB::table('team_chat_members')->whereIn('chat_id', $ids)->whereNull('left_at')->groupBy('chat_id')->select('chat_id', DB::raw('count(*) as c'))->pluck('c', 'chat_id');
        $others = DB::table('team_chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.chat_id', $rows->where('type', 'direct')->pluck('id'))->where('m.user_id', '!=', $uid)
            ->get(['m.chat_id', 'u.id as uid', 'u.name', 'u.avatar'])->keyBy('chat_id');

        $data = $rows->map(function ($c) use ($unread, $last, $counts, $others, $mentionUnread, $lastSenders) {
            $o = $others[$c->id] ?? null; $l = $last[$c->last_message_id] ?? null;
            return [
                'id' => $c->id, 'type' => $c->type,
                'name' => $c->type === 'direct' ? ($o->name ?? 'Chat') : $c->name,
                'avatar' => $c->type === 'direct' ? ($o->avatar ?? null) : $c->avatar_url,
                'other_user_id' => $o->uid ?? null,
                'members_count' => (int) ($counts[$c->id] ?? 0), 'muted' => $this->mutedNow($c), 'muted_until' => $c->muted ? $c->muted_until : null, 'my_role' => $c->my_role,
                'unread' => (int) ($unread[$c->id] ?? 0), 'unread_mentions' => (int) ($mentionUnread[$c->id] ?? 0),
                'last_sender' => $l && $l->sender_id ? ($lastSenders[$l->sender_id] ?? null) : null, 'last_sender_id' => $l->sender_id ?? null,
                'last_message' => $l ? $this->preview($l) : null, 'last_message_at' => $c->last_message_at,
            ];
        });
        return response()->json(['data' => $data, 'page' => $page, 'has_more' => $hasMore, 'unread_total' => $unreadTotal, 'counts' => $tabCounts]);
    }

    private function preview(object $m): string { return \App\Modules\TeamChat\Support\TeamChatPush::preview($m); }

    /** POST /team-chat/chats — {type:direct,user_id} | {type:group,name,member_ids[]} */
    public function create(Request $r): JsonResponse
    {
        $cid = $this->cid(); $uid = $this->uid();
        $d = $r->validate(['type' => 'required|in:direct,group', 'user_id' => 'required_if:type,direct|integer',
            'name' => 'required_if:type,group|string|max:80', 'member_ids' => 'array', 'member_ids.*' => 'integer']);
        $valid = fn (array $ids) => DB::table('users')->where('company_id', $cid)->where('is_active', true)->whereIn('id', $ids)->pluck('id')->all();

        if ($d['type'] === 'direct') {
            abort_unless($valid([$d['user_id']]) && (int) $d['user_id'] !== $uid, 422, 'Pick a team member.');
            $key = min($uid, (int) $d['user_id']) . ':' . max($uid, (int) $d['user_id']);
            $chat = DB::table('team_chats')->where('company_id', $cid)->where('direct_key', $key)->first();
            if (!$chat) {
                $id = DB::table('team_chats')->insertGetId(['company_id' => $cid, 'type' => 'direct', 'direct_key' => $key,
                    'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
                foreach ([$uid, (int) $d['user_id']] as $u) {
                    DB::table('team_chat_members')->insert(['chat_id' => $id, 'user_id' => $u, 'role' => 'member', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                return response()->json(['id' => $id], 201);
            }
            DB::table('team_chat_members')->where('chat_id', $chat->id)->update(['left_at' => null]);
            return response()->json(['id' => $chat->id]);
        }

        $members = array_values(array_unique(array_filter($valid($d['member_ids'] ?? []), fn ($i) => $i !== $uid)));
        abort_if(!$members, 422, 'Add at least one member.');
        $id = DB::table('team_chats')->insertGetId(['company_id' => $cid, 'type' => 'group', 'name' => $d['name'],
            'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_chat_members')->insert(['chat_id' => $id, 'user_id' => $uid, 'role' => 'admin', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($members as $u) {
            DB::table('team_chat_members')->insert(['chat_id' => $id, 'user_id' => $u, 'role' => 'member', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->system($id, $this->name($uid) . ' created the group "' . $d['name'] . '"');
        return response()->json(['id' => $id], 201);
    }

    /** GET /team-chat/chats/{id} — details + members. */
    public function show(int $id): JsonResponse
    {
        $me = $this->member($id);
        $chat = DB::table('team_chats')->where('id', $id)->first();
        $members = DB::table('team_chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.chat_id', $id)->whereNull('m.left_at')
            ->orderByRaw("m.role = 'admin' DESC")->orderBy('u.name')->get(['u.id', 'u.name', 'u.avatar', 'm.role']);
        return response()->json(['id' => $id, 'type' => $chat->type, 'name' => $chat->name, 'avatar' => $chat->avatar_url,
            'only_admins_post' => (bool) $chat->only_admins_post, 'only_admins_edit' => (bool) $chat->only_admins_edit, 'only_admins_add' => (bool) $chat->only_admins_add, 'my_role' => $me->role, 'muted' => $this->mutedNow($me), 'muted_until' => $me->muted ? $me->muted_until : null,
            'created_by' => $chat->created_by, 'members' => $members,
            // Admins can see who has left (history stays; useful when a group is being reviewed).
            'former_members' => $me->role === 'admin' || $this->isModerator() ? DB::table('team_chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.chat_id', $id)->whereNotNull('m.left_at')->orderByDesc('m.left_at')->get(['u.id', 'u.name', 'u.avatar', 'm.left_at']) : []]);
    }

    /** PATCH /team-chat/chats/{id} — rename (admins, or anyone if "edit info" is open) + permission switches (admins). */
    public function update(Request $r, int $id): JsonResponse
    {
        $me = $this->member($id);
        abort_unless($me->type === 'group', 422, 'Not a group.');
        $d = $r->validate(['name' => 'sometimes|string|max:80', 'only_admins_post' => 'sometimes|boolean',
            'only_admins_edit' => 'sometimes|boolean', 'only_admins_add' => 'sometimes|boolean']);
        $isAdmin = $me->role === 'admin';
        if (isset($d['name'])) { abort_unless($isAdmin || !$me->only_admins_edit, 403, 'Only admins can edit group info.'); }
        if (array_diff_key($d, ['name' => 1])) { abort_unless($isAdmin, 403, 'Only group admins can change permissions.'); }
        DB::table('team_chats')->where('id', $id)->update($d + ['updated_at' => now()]);
        $who = $this->name($this->uid());
        if (isset($d['name'])) { $this->system($id, "{$who} renamed the group to \"{$d['name']}\""); }
        if (array_key_exists('only_admins_post', $d)) { $this->system($id, $who . ($d['only_admins_post'] ? ' restricted sending to admins' : ' allowed everyone to send messages')); }
        if (array_key_exists('only_admins_edit', $d)) { $this->system($id, $who . ($d['only_admins_edit'] ? ' restricted group info editing to admins' : ' allowed everyone to edit group info')); }
        if (array_key_exists('only_admins_add', $d)) { $this->system($id, $who . ($d['only_admins_add'] ? ' restricted adding members to admins' : ' allowed everyone to add members')); }
        $this->emit($id, 'info');
        return response()->json(['ok' => true]);
    }

    /** POST /team-chat/chats/{id}/avatar (multipart `file`) — group photo. */
    public function avatar(Request $r, int $id): JsonResponse
    {
        $me = $this->member($id);
        abort_unless($me->type === 'group', 422, 'Not a group.');
        abort_unless($me->role === 'admin' || !$me->only_admins_edit, 403, 'Only admins can edit group info.');
        $r->validate(['file' => 'required|image|max:5120']);
        $path = $r->file('file')->store('team-chat/' . $this->cid() . '/avatars', 'public');
        DB::table('team_chats')->where('id', $id)->update(['avatar_url' => Storage::disk('public')->url($path), 'updated_at' => now()]);
        $this->system($id, $this->name($this->uid()) . ' changed the group photo');
        return response()->json(['ok' => true]);
    }

    /** POST /team-chat/chats/{id}/members {user_ids[]} */
    public function addMembers(Request $r, int $id): JsonResponse
    {
        $me = $this->member($id);
        abort_unless($me->type === 'group' && ($me->role === 'admin' || !$me->only_admins_add), 403, 'Only group admins can add members.');
        $d = $r->validate(['user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer']);
        $ids = DB::table('users')->where('company_id', $this->cid())->where('is_active', true)->whereIn('id', $d['user_ids'])->pluck('id');
        foreach ($ids as $u) {
            $row = DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $u)->first();
            if ($row && !$row->left_at) { continue; }
            $row
                ? DB::table('team_chat_members')->where('id', $row->id)->update(['left_at' => null, 'role' => 'member', 'joined_at' => now(), 'updated_at' => now()])
                : DB::table('team_chat_members')->insert(['chat_id' => $id, 'user_id' => $u, 'role' => 'member', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->system($id, $this->name($this->uid()) . ' added ' . $this->name($u));
        }
        return response()->json(['ok' => true]);
    }

    /** DELETE /team-chat/chats/{id}/members/{userId} — admin removes someone, or you leave. */
    public function removeMember(int $id, int $userId): JsonResponse
    {
        $me = $this->member($id);
        abort_unless($me->type === 'group', 422, 'Not a group.');
        $self = $userId === $this->uid();
        abort_unless($self || $me->role === 'admin', 403, 'Only group admins can remove members.');
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $userId)->update(['left_at' => now(), 'updated_at' => now()]);
        // Never leave a group without an admin: promote the longest-standing member.
        $left = DB::table('team_chat_members')->where('chat_id', $id)->whereNull('left_at');
        if ((clone $left)->count() && !(clone $left)->where('role', 'admin')->exists()) {
            DB::table('team_chat_members')->where('id', (clone $left)->orderBy('joined_at')->value('id'))->update(['role' => 'admin']);
        }
        $this->system($id, $self ? $this->name($userId) . ' left' : $this->name($this->uid()) . ' removed ' . $this->name($userId));
        return response()->json(['ok' => true]);
    }

    /** POST /team-chat/chats/{id}/members/{userId}/role {role: admin|member} */
    public function setRole(Request $r, int $id, int $userId): JsonResponse
    {
        $me = $this->member($id); $this->adminOnly($me);
        $role = $r->validate(['role' => 'required|in:admin,member'])['role'];
        if ($role === 'member') {
            $others = DB::table('team_chat_members')->where('chat_id', $id)->whereNull('left_at')->where('role', 'admin')->where('user_id', '!=', $userId)->exists();
            abort_unless($others, 422, 'A group needs at least one admin.');
        }
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $userId)->whereNull('left_at')->update(['role' => $role]);
        $this->system($id, $this->name($userId) . ($role === 'admin' ? ' is now an admin' : ' is no longer an admin'));
        return response()->json(['ok' => true]);
    }

    /** DELETE /team-chat/chats/{id} — admin deletes the whole group. */
    public function destroy(int $id): JsonResponse
    {
        $me = $this->member($id); $this->adminOnly($me);
        DB::table('team_chats')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /team-chat/chats/{id}/media?type=media|docs|audio|links&before_id=&limit= — the chat's shared files, newest first. */
    public function media(Request $r, int $id): JsonResponse
    {
        $this->member($id);
        $type = $r->query('type', 'media');
        $q = DB::table('team_chat_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.chat_id', $id)->whereNull('m.deleted_at')
            ->when($r->filled('before_id'), fn ($x) => $x->where('m.id', '<', (int) $r->query('before_id')))
            ->orderByDesc('m.id')->limit(min(max((int) $r->query('limit', 60), 1), 200));
        match ($type) {
            'docs'  => $q->where('m.type', 'file'),
            'audio' => $q->where('m.type', 'audio'),
            'links' => $q->where('m.type', 'text')->where('m.body', 'regexp', 'https?://'),
            default => $q->whereIn('m.type', ['image', 'video']),
        };
        $rows = $q->get(['m.id', 'm.type', 'm.body', 'm.attachment_url', 'm.attachment_name', 'm.attachment_size', 'm.attachment_mime', 'm.duration_seconds', 'm.created_at', 'u.name as sender_name']);
        $data = $rows->map(function ($m) use ($type) {
            $links = [];
            if ($type === 'links') { preg_match_all('~https?://[^\s<>"\']+~i', (string) $m->body, $mm); $links = $mm[0]; }
            return ['id' => $m->id, 'type' => $m->type, 'url' => $m->attachment_url, 'name' => $m->attachment_name, 'size' => $m->attachment_size,
                    'mime' => $m->attachment_mime, 'duration' => $m->duration_seconds, 'sender_name' => $m->sender_name, 'created_at' => $m->created_at,
                    'links' => $links, 'body' => $type === 'links' ? $m->body : null];
        })->values();
        $counts = DB::table('team_chat_messages')->where('chat_id', $id)->whereNull('deleted_at')
            ->selectRaw("SUM(type IN ('image','video')) as media, SUM(type = 'file') as docs, SUM(type = 'audio') as audio, SUM(type = 'text' AND body REGEXP 'https?://') as links")->first();
        return response()->json(['data' => $data, 'counts' => ['media' => (int) $counts->media, 'docs' => (int) $counts->docs, 'audio' => (int) $counts->audio, 'links' => (int) $counts->links]]);
    }

    /** GET /team-chat/mentions — messages that @mention me, across all my chats (quick access). */
    public function mentions(): JsonResponse
    {
        $uid = $this->uid();
        $rows = DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at');
            })->join('team_chats as c', 'c.id', '=', 'msg.chat_id')->leftJoin('users as u', 'u.id', '=', 'msg.sender_id')
            ->where('c.company_id', $this->cid())->whereNull('msg.deleted_at')->whereRaw('JSON_CONTAINS(msg.mentions, CAST(? AS JSON))', [(string) $uid])
            ->orderByDesc('msg.id')->limit(50)
            ->get(['msg.id', 'msg.chat_id', 'msg.body', 'msg.created_at', 'm.last_read_message_id', 'c.name as chat_name', 'c.type as chat_type', 'u.name as sender_name']);
        return response()->json(['data' => $rows->map(fn ($x) => [
            'id' => $x->id, 'chat_id' => $x->chat_id, 'chat_name' => $x->chat_type === 'group' ? $x->chat_name : $x->sender_name, 'sender_name' => $x->sender_name,
            'body' => $x->body, 'created_at' => $x->created_at, 'unread' => $x->id > $x->last_read_message_id])]);
    }

    /** GET /team-chat/search?q= — messages across every chat I'm in. */
    public function search(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q'));
        abort_if(mb_strlen($q) < 2, 422, 'Type at least 2 characters.');
        $uid = $this->uid();
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $rows = DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at');
            })->join('team_chats as c', 'c.id', '=', 'msg.chat_id')->leftJoin('users as u', 'u.id', '=', 'msg.sender_id')
            ->where('c.company_id', $this->cid())->whereNull('msg.deleted_at')->where('msg.type', '!=', 'system')
            ->where(fn ($w) => $w->where('msg.body', 'like', $like)->orWhere('msg.attachment_name', 'like', $like)->orWhere('msg.contact_name', 'like', $like))
            ->orderByDesc('msg.id')->limit(40)
            ->get(['msg.id', 'msg.chat_id', 'msg.type', 'msg.body', 'msg.attachment_name', 'msg.contact_name', 'msg.created_at', 'c.name as chat_name', 'c.type as chat_type', 'c.direct_key', 'u.name as sender_name']);
        $dm = $rows->where('chat_type', 'direct')->pluck('chat_id');
        $others = $dm->isEmpty() ? collect() : DB::table('team_chat_members as mm')->join('users as uu', 'uu.id', '=', 'mm.user_id')
            ->whereIn('mm.chat_id', $dm)->where('mm.user_id', '!=', $uid)->pluck('uu.name', 'mm.chat_id');
        return response()->json(['data' => $rows->map(fn ($x) => [
            'id' => $x->id, 'chat_id' => $x->chat_id, 'chat_type' => $x->chat_type,
            'chat_name' => $x->chat_type === 'group' ? $x->chat_name : ($others[$x->chat_id] ?? 'Chat'), 'sender_name' => $x->sender_name,
            'text' => $x->body ?: ($x->attachment_name ?: $x->contact_name), 'created_at' => $x->created_at])]);
    }

    /** POST /team-chat/chats/{id}/mute {muted, duration?: 8h|1w|always} — direct chats and groups alike. */
    public function mute(Request $r, int $id): JsonResponse
    {
        $this->member($id);
        $d = $r->validate(['muted' => 'required|boolean', 'duration' => 'nullable|in:8h,1w,always']);
        $until = !$d['muted'] ? null : match ($d['duration'] ?? 'always') { '8h' => now()->addHours(8), '1w' => now()->addWeek(), default => null };
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())
            ->update(['muted' => (bool) $d['muted'], 'muted_until' => $until]);
        $this->emit($id, 'mute');
        return response()->json(['ok' => true, 'muted_until' => $until?->toIso8601String()]);
    }

    /** POST /team-chat/chats/{id}/delivered — this device has received everything up to now (REST fallback of chat:delivered). */
    public function delivered(int $id): JsonResponse
    {
        $this->member($id);
        $last = (int) DB::table('team_chat_messages')->where('chat_id', $id)->max('id');
        $this->recordReceipts($id, $this->uid(), (int) DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->value('last_delivered_message_id'), $last, false);
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())
            ->update(['last_delivered_message_id' => DB::raw('GREATEST(last_delivered_message_id, ' . $last . ')')]);
        $this->emit($id, 'delivered', ['last_id' => $last]);
        return response()->json(['ok' => true]);
    }

    /** POST /team-chat/chats/{id}/read */
    public function read(int $id): JsonResponse
    {
        $this->member($id);
        $last = (int) DB::table('team_chat_messages')->where('chat_id', $id)->max('id');
        $prev = (int) DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->value('last_read_message_id');
        $this->recordReceipts($id, $this->uid(), min($prev, (int) DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->value('last_delivered_message_id')), $last, true);
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->update(['last_read_message_id' => $last, 'last_delivered_message_id' => DB::raw('GREATEST(last_delivered_message_id, ' . $last . ')')]);
        $this->emit($id, 'read', ['last_id' => $last]);
        return response()->json(['ok' => true]);
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /** GET /team-chat/chats/{id}/messages?after_id=&before_id=&limit=40&q= */
    public function messages(Request $r, int $id): JsonResponse
    {
        $this->member($id);
        $limit = min(max((int) $r->query('limit', 40), 1), 100);
        $q = $this->notHidden(DB::table('team_chat_messages')->where('chat_id', $id));
        if ($r->filled('q')) { $q->whereNull('deleted_at')->where('body', 'like', '%' . $r->query('q') . '%'); }
        if ($r->filled('around_id')) {
            $mid = (int) $r->query('around_id');
            $before = (clone $q)->where('id', '<=', $mid)->orderByDesc('id')->limit(25)->get()->reverse();
            $after = (clone $q)->where('id', '>', $mid)->orderBy('id')->limit(25)->get();
            $rows = $before->concat($after)->values();
        } elseif ($r->filled('after_id')) {
            $rows = (clone $q)->where('id', '>', (int) $r->query('after_id'))->orderBy('id')->limit(200)->get();
        } else {
            if ($r->filled('before_id')) { $q->where('id', '<', (int) $r->query('before_id')); }
            $rows = $q->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        }
        return response()->json(['data' => $this->format($rows, $id)]);
    }

    /** Edited / deleted messages change after the app last saw them, so the app also asks for these ids. */
    public function byIds(Request $r, int $id): JsonResponse
    {
        $this->member($id);
        $ids = array_filter(array_map('intval', explode(',', (string) $r->query('ids'))));
        return response()->json(['data' => $this->format($this->notHidden(DB::table('team_chat_messages')->where('chat_id', $id)->whereIn('id', $ids))->orderBy('id')->get(), $id)]);
    }

    private function format($rows, int $chatId): array
    {
        $ids = $rows->pluck('id');
        $senders = DB::table('users')->whereIn('id', $rows->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $replies = DB::table('team_chat_messages')->whereIn('id', $rows->pluck('reply_to_id')->filter())->get()->keyBy('id');
        $react = DB::table('team_chat_reactions')->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $members = DB::table('team_chat_members')->where('chat_id', $chatId)->whereNull('left_at')->get(['user_id', 'last_read_message_id', 'last_delivered_message_id']);
        $starred = DB::table('team_chat_stars')->whereIn('message_id', $ids)->where('user_id', $this->uid())->pluck('message_id')->flip();
        $pinned = DB::table('team_chat_pins')->where('chat_id', $chatId)->whereIn('message_id', $ids)->pluck('message_id')->flip();
        $uid = $this->uid();

        return $rows->map(function ($m) use ($senders, $replies, $react, $members, $uid) {
            $rep = $m->reply_to_id ? ($replies[$m->reply_to_id] ?? null) : null;
            $others = $members->where('user_id', '!=', $m->sender_id);
            $seen = $others->where('last_read_message_id', '>=', $m->id)->count();
            $delivered = $others->filter(fn ($x) => max($x->last_delivered_message_id, $x->last_read_message_id) >= $m->id)->count();
            return [
                'id' => $m->id, 'type' => $m->type, 'sender_id' => $m->sender_id, 'sender_name' => $m->sender_id ? ($senders[$m->sender_id] ?? '') : null,
                'mine' => (int) $m->sender_id === $uid,
                'body' => $m->deleted_at ? null : $m->body, 'deleted' => (bool) $m->deleted_at, 'edited' => (bool) $m->edited_at,
                // Only the sender learns who removed their message; company admins read the original via /original.
                'deleted_by_name' => $m->deleted_at && (int) $m->sender_id === $uid && $m->deleted_by ? ($senders[$m->deleted_by] ?? DB::table('users')->where('id', $m->deleted_by)->value('name')) : null,
                'attachment_url' => $m->deleted_at ? null : $m->attachment_url, 'attachment_name' => $m->attachment_name, 'attachment_size' => $m->attachment_size,
                'attachment_mime' => $m->attachment_mime, 'duration' => $m->duration_seconds, 'forwarded' => (bool) $m->forwarded,
                'latitude' => $m->latitude !== null ? (float) $m->latitude : null, 'longitude' => $m->longitude !== null ? (float) $m->longitude : null,
                'location_label' => $m->location_label, 'contact_name' => $m->contact_name, 'contact_phone' => $m->contact_phone,
                'mentions' => $m->mentions ? json_decode($m->mentions, true) : [],
                'reply_to' => $rep ? ['id' => $rep->id, 'sender_name' => $rep->sender_id ? ($senders[$rep->sender_id] ?? DB::table('users')->where('id', $rep->sender_id)->value('name')) : null,
                                      'preview' => $this->preview($rep), 'type' => $rep->type, 'thumb' => $rep->type === 'image' && !$rep->deleted_at ? $rep->attachment_url : null] : null,
                'reactions' => collect($react[$m->id] ?? [])->groupBy('emoji')->map(fn ($g, $e) => ['emoji' => $e, 'count' => $g->count(), 'mine' => $g->contains('user_id', $uid)])->values(),
                'starred' => isset($starred[$m->id]), 'pinned' => isset($pinned[$m->id]),
                'seen_by_count' => $seen, 'delivered_count' => $delivered, 'recipients' => $others->count(), 'created_at' => $m->created_at,
            ];
        })->all();
    }

    /** POST /team-chat/chats/{id}/messages — body and/or file (multipart), reply_to_id, mentions[] */
    public function send(Request $r, int $id): JsonResponse
    {
        $me = $this->member($id);
        abort_if($me->type === 'group' && $me->only_admins_post && $me->role !== 'admin', 403, 'Only admins can post in this group.');
        $d = $r->validate([
            'body' => 'nullable|string|max:4000', 'reply_to_id' => 'nullable|integer', 'mentions' => 'nullable|array', 'mentions.*' => 'integer',
            'file' => 'nullable|file|max:25600', 'duration' => 'nullable|integer|min:0|max:7200',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'location_label' => 'nullable|string|max:200',
            'contact_name' => 'nullable|string|max:120', 'contact_phone' => 'nullable|string|max:40',
        ]);
        $isLocation = isset($d['latitude'], $d['longitude']);
        $isContact = filled($d['contact_name'] ?? null);
        abort_if(!filled($d['body'] ?? null) && !$r->hasFile('file') && !$isLocation && !$isContact, 422, 'Write a message or attach something.');

        $type = $isLocation ? 'location' : ($isContact ? 'contact' : 'text');
        $url = $name = $size = $mime = null;
        if ($r->hasFile('file')) {
            $f = $r->file('file');
            $path = $f->store('team-chat/' . $this->cid(), 'public');
            $url = Storage::disk('public')->url($path); $name = $f->getClientOriginalName(); $size = $f->getSize(); $mime = (string) $f->getMimeType();
            $type = match (true) {
                str_starts_with($mime, 'image/') => 'image', str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') || in_array($f->getClientOriginalExtension(), ['m4a', 'aac', 'mp3', 'ogg', 'opus', 'wav', 'webm'], true) => 'audio',
                default => 'file',
            };
        }
        if (!empty($d['reply_to_id'])) {
            abort_unless(DB::table('team_chat_messages')->where('id', $d['reply_to_id'])->where('chat_id', $id)->exists(), 422, 'Bad reply.');
        }
        // Only people actually in this chat can be mentioned.
        $mentions = !empty($d['mentions'])
            ? DB::table('team_chat_members')->where('chat_id', $id)->whereNull('left_at')->whereIn('user_id', $d['mentions'])->pluck('user_id')->map(fn ($v) => (int) $v)->all()
            : [];
        $mid = DB::table('team_chat_messages')->insertGetId([
            'chat_id' => $id, 'company_id' => $this->cid(), 'sender_id' => $this->uid(), 'type' => $type, 'body' => $d['body'] ?? null,
            'attachment_url' => $url, 'attachment_name' => $name, 'attachment_size' => $size, 'attachment_mime' => $mime,
            'duration_seconds' => $d['duration'] ?? null, 'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null,
            'location_label' => $d['location_label'] ?? null, 'contact_name' => $d['contact_name'] ?? null, 'contact_phone' => $d['contact_phone'] ?? null,
            'reply_to_id' => $d['reply_to_id'] ?? null, 'mentions' => $mentions ? json_encode($mentions) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('team_chats')->where('id', $id)->update(['last_message_id' => $mid, 'last_message_at' => now()]);
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->update(['last_read_message_id' => $mid]);
        $row = DB::table('team_chat_messages')->where('id', $mid)->first();
        $this->emit($id, 'message', ['message_id' => $mid]);
        $this->push($id, $mid, $this->preview($row), $mentions);
        return response()->json(['id' => $mid], 201);
    }

    /** POST /team-chat/messages/{mid}/forward {chat_ids[]} — copy a message into other chats the caller is in. */
    public function forward(Request $r, int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        abort_if($m->deleted_at || $m->type === 'system', 422, 'Cannot forward this message.');
        $chatIds = $r->validate(['chat_ids' => 'required|array|min:1|max:10', 'chat_ids.*' => 'integer'])['chat_ids'];
        foreach ($chatIds as $cid) {
            $mem = DB::table('team_chat_members as mm')->join('team_chats as c', 'c.id', '=', 'mm.chat_id')->where('mm.chat_id', $cid)
                ->where('mm.user_id', $this->uid())->whereNull('mm.left_at')->where('c.company_id', $this->cid())->select('mm.role', 'c.type', 'c.only_admins_post')->first();
            if (!$mem || ($mem->type === 'group' && $mem->only_admins_post && $mem->role !== 'admin')) { continue; }
            $copy = (array) $m;
            unset($copy['id'], $copy['reply_to_id'], $copy['mentions'], $copy['edited_at'], $copy['deleted_at']);
            $new = DB::table('team_chat_messages')->insertGetId(['chat_id' => $cid, 'sender_id' => $this->uid(), 'forwarded' => true,
                'created_at' => now(), 'updated_at' => now()] + $copy);
            DB::table('team_chats')->where('id', $cid)->update(['last_message_id' => $new, 'last_message_at' => now()]);
            DB::table('team_chat_members')->where('chat_id', $cid)->where('user_id', $this->uid())->update(['last_read_message_id' => $new]);
            $this->emit($cid, 'message', ['message_id' => $new]);
            $this->push($cid, $new, $this->preview(DB::table('team_chat_messages')->where('id', $new)->first()), []);
        }
        return response()->json(['ok' => true]);
    }

    private function ownMessage(int $mid): object
    {
        $m = DB::table('team_chat_messages')->where('id', $mid)->where('company_id', $this->cid())->first();
        abort_unless($m, 404);
        $this->member((int) $m->chat_id);
        return $m;
    }

    /** PATCH /team-chat/messages/{mid} {body} — sender edits own text. */
    public function edit(Request $r, int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        abort_unless((int) $m->sender_id === $this->uid() && !$m->deleted_at, 403, 'You can only edit your own messages.');
        $body = $r->validate(['body' => 'required|string|max:4000'])['body'];
        DB::table('team_chat_messages')->where('id', $mid)->update(['body' => $body, 'edited_at' => now(), 'updated_at' => now()]);
        $this->emit((int) $m->chat_id, 'edit', ['message_id' => $mid]);
        return response()->json(['ok' => true]);
    }

    /** DELETE /team-chat/messages/{mid}?scope=me|everyone — "for me" hides it from you only; "for everyone" (sender or
     *  group admin) leaves a tombstone for members while the original stays on file for company admins. */
    public function deleteMessage(Request $r, int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        $scope = $r->input('scope', $r->query('scope', 'everyone'));
        if ($scope === 'me') {
            DB::table('team_chat_hides')->updateOrInsert(['message_id' => $mid, 'user_id' => $this->uid()], ['created_at' => now()]);
            return response()->json(['ok' => true]);
        }
        $me = $this->member((int) $m->chat_id);
        abort_unless((int) $m->sender_id === $this->uid() || ($me->type === 'group' && $me->role === 'admin'), 403, 'Not allowed.');
        DB::table('team_chat_messages')->where('id', $mid)->update(['deleted_at' => now(), 'deleted_by' => $this->uid(), 'updated_at' => now()]);
        $this->emit((int) $m->chat_id, 'delete', ['message_id' => $mid]);
        return response()->json(['ok' => true]);
    }

    /** GET /team-chat/messages/{mid}/original — what a deleted message said, for company admins (moderators). */
    public function original(int $mid): JsonResponse
    {
        $this->moderatorOnly();
        $m = DB::table('team_chat_messages as m')->join('team_chats as c', 'c.id', '=', 'm.chat_id')->where('m.id', $mid)->where('c.company_id', $this->cid())
            ->first(['m.*', 'c.name as chat_name', 'c.type as chat_type']);
        abort_unless($m, 404);
        return response()->json([
            'id' => $m->id, 'type' => $m->type, 'body' => $m->body, 'attachment_url' => $m->attachment_url, 'attachment_name' => $m->attachment_name,
            'attachment_mime' => $m->attachment_mime, 'latitude' => $m->latitude, 'longitude' => $m->longitude, 'location_label' => $m->location_label,
            'contact_name' => $m->contact_name, 'contact_phone' => $m->contact_phone, 'chat_name' => $m->chat_type === 'group' ? $m->chat_name : 'Direct chat',
            'sender_name' => $m->sender_id ? $this->name((int) $m->sender_id) : null, 'sent_at' => $m->created_at,
            'deleted' => (bool) $m->deleted_at, 'deleted_at' => $m->deleted_at, 'deleted_by_name' => $m->deleted_by ? $this->name((int) $m->deleted_by) : null,
        ]);
    }

    /** POST /team-chat/messages/{mid}/react {emoji} — toggles. */
    public function react(Request $r, int $mid): JsonResponse
    {
        $this->ownMessage($mid);
        $emoji = $r->validate(['emoji' => 'required|string|max:16'])['emoji'];
        $q = DB::table('team_chat_reactions')->where(['message_id' => $mid, 'user_id' => $this->uid(), 'emoji' => $emoji]);
        $q->exists() ? $q->delete() : DB::table('team_chat_reactions')->insert(['message_id' => $mid, 'user_id' => $this->uid(), 'emoji' => $emoji, 'created_at' => now()]);
        $this->emit((int) DB::table('team_chat_messages')->where('id', $mid)->value('chat_id'), 'reaction', ['message_id' => $mid]);
        return response()->json(['ok' => true]);
    }

    /** GET /team-chat/messages/{mid}/info — delivery + read times per recipient. Sender and company admins only. */
    public function info(int $mid): JsonResponse
    {
        $m = DB::table('team_chat_messages as m')->join('team_chats as c', 'c.id', '=', 'm.chat_id')->where('m.id', $mid)->where('c.company_id', $this->cid())->first(['m.*']);
        abort_unless($m, 404);
        abort_unless((int) $m->sender_id === $this->uid() || $this->isModerator(), 403, 'Only the sender or a company admin can see message info.');
        $rows = DB::table('team_chat_members as mem')->join('users as u', 'u.id', '=', 'mem.user_id')
            ->leftJoin('team_chat_receipts as r', fn ($j) => $j->on('r.message_id', '=', DB::raw((int) $mid))->on('r.user_id', '=', 'mem.user_id'))
            ->where('mem.chat_id', $m->chat_id)->where('mem.user_id', '!=', $m->sender_id)->whereNull('mem.left_at')
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.avatar', 'r.delivered_at', 'r.read_at', 'mem.last_read_message_id', 'mem.last_delivered_message_id']);
        return response()->json([
            'sent_at' => $m->created_at, 'sender_name' => $m->sender_id ? $this->name((int) $m->sender_id) : null,
            'recipients' => $rows->map(fn ($x) => [
                'id' => $x->id, 'name' => $x->name, 'avatar' => $x->avatar,
                // Older messages (before receipts existed) fall back to the marker: delivered/read, time unknown.
                'delivered' => (bool) $x->delivered_at || max($x->last_delivered_message_id, $x->last_read_message_id) >= $mid,
                'read' => (bool) $x->read_at || $x->last_read_message_id >= $mid,
                'delivered_at' => $x->delivered_at, 'read_at' => $x->read_at])->values(),
        ]);
    }

    // ── Stars, pins, reaction details ────────────────────────────────────────

    /** POST /team-chat/messages/{mid}/star — toggles a personal bookmark. */
    public function star(int $mid): JsonResponse
    {
        $this->ownMessage($mid);
        $q = DB::table('team_chat_stars')->where(['message_id' => $mid, 'user_id' => $this->uid()]);
        if ($q->exists()) { $q->delete(); $on = false; }
        else { DB::table('team_chat_stars')->insert(['message_id' => $mid, 'user_id' => $this->uid(), 'created_at' => now()]); $on = true; }
        return response()->json(['ok' => true, 'starred' => $on]);
    }

    /** GET /team-chat/starred?chat_id= — everything I starred (optionally in one chat), newest star first. */
    public function starred(Request $r): JsonResponse
    {
        $uid = $this->uid();
        $rows = DB::table('team_chat_stars as st')->join('team_chat_messages as msg', 'msg.id', '=', 'st.message_id')
            ->join('team_chat_members as m', fn ($j) => $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid)->whereNull('m.left_at'))
            ->join('team_chats as c', 'c.id', '=', 'msg.chat_id')->leftJoin('users as u', 'u.id', '=', 'msg.sender_id')
            ->where('st.user_id', $uid)->where('c.company_id', $this->cid())->whereNull('msg.deleted_at')
            ->when($r->filled('chat_id'), fn ($q) => $q->where('msg.chat_id', (int) $r->query('chat_id')))
            ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('team_chat_hides as h')->whereColumn('h.message_id', 'msg.id')->where('h.user_id', $uid))
            ->orderByDesc('st.created_at')->limit(100)
            ->get(['msg.id', 'msg.chat_id', 'msg.type', 'msg.body', 'msg.attachment_url', 'msg.attachment_name', 'msg.contact_name', 'msg.created_at',
                   'c.name as chat_name', 'c.type as chat_type', 'u.name as sender_name', 'msg.sender_id']);
        $dm = $rows->where('chat_type', 'direct')->pluck('chat_id')->unique();
        $others = $dm->isEmpty() ? collect() : DB::table('team_chat_members as mm')->join('users as uu', 'uu.id', '=', 'mm.user_id')
            ->whereIn('mm.chat_id', $dm)->where('mm.user_id', '!=', $uid)->pluck('uu.name', 'mm.chat_id');
        return response()->json(['data' => $rows->map(fn ($x) => [
            'id' => $x->id, 'chat_id' => $x->chat_id, 'chat_name' => $x->chat_type === 'group' ? $x->chat_name : ($others[$x->chat_id] ?? 'Chat'),
            'type' => $x->type, 'sender_name' => (int) $x->sender_id === $uid ? 'You' : $x->sender_name,
            'text' => $x->body ?: ($x->attachment_name ?: $x->contact_name), 'attachment_url' => $x->attachment_url, 'created_at' => $x->created_at])]);
    }

    /** POST /team-chat/messages/{mid}/pin — toggle. Max 3 pins per chat (oldest drops off). Groups: admins, or anyone if "edit info" is open. */
    public function pin(int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        $me = $this->member((int) $m->chat_id);
        abort_unless($me->type === 'direct' || $me->role === 'admin' || !$me->only_admins_edit, 403, 'Only group admins can pin messages.');
        abort_if($m->deleted_at || $m->type === 'system', 422, 'This message cannot be pinned.');
        $q = DB::table('team_chat_pins')->where(['chat_id' => $m->chat_id, 'message_id' => $mid]);
        if ($q->exists()) {
            $q->delete(); $on = false;
        } else {
            DB::table('team_chat_pins')->insert(['chat_id' => $m->chat_id, 'message_id' => $mid, 'pinned_by' => $this->uid(), 'pinned_at' => now()]);
            $keep = DB::table('team_chat_pins')->where('chat_id', $m->chat_id)->orderByDesc('pinned_at')->limit(3)->pluck('message_id');
            DB::table('team_chat_pins')->where('chat_id', $m->chat_id)->whereNotIn('message_id', $keep)->delete();
            $on = true;
        }
        $this->system((int) $m->chat_id, $this->name($this->uid()) . ($on ? ' pinned a message' : ' unpinned a message'));
        $this->emit((int) $m->chat_id, 'pin', ['message_id' => $mid]);
        return response()->json(['ok' => true, 'pinned' => $on]);
    }

    /** GET /team-chat/chats/{id}/pins — pinned messages, newest pin first. */
    public function pins(int $id): JsonResponse
    {
        $this->member($id);
        $ids = DB::table('team_chat_pins')->where('chat_id', $id)->orderByDesc('pinned_at')->pluck('message_id');
        $rows = $this->notHidden(DB::table('team_chat_messages')->where('chat_id', $id)->whereIn('id', $ids)->whereNull('deleted_at'));
        $by = $rows->get()->keyBy('id');
        $ordered = $ids->map(fn ($i) => $by[$i] ?? null)->filter()->values();
        return response()->json(['data' => $this->format($ordered, $id)]);
    }

    /** GET /team-chat/messages/{mid}/reactions — who reacted with what. */
    public function reactions(int $mid): JsonResponse
    {
        $this->ownMessage($mid);
        $rows = DB::table('team_chat_reactions as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.message_id', $mid)->orderBy('r.created_at')
            ->get(['u.id', 'u.name', 'u.avatar', 'r.emoji']);
        return response()->json(['data' => $rows]);
    }

    // ── Reporting + company moderation ───────────────────────────────────────

    /** POST /team-chat/messages/{mid}/report {reason, note?} — any member can report a message they can see. */
    public function report(Request $r, int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        abort_if($m->type === 'system', 422, 'This message cannot be reported.');
        abort_if((int) $m->sender_id === $this->uid(), 422, 'You cannot report your own message.');
        $d = $r->validate(['reason' => 'required|in:spam,harassment,inappropriate,other', 'note' => 'nullable|string|max:500']);
        $snapshot = ['type' => $m->type, 'body' => $m->body, 'attachment_name' => $m->attachment_name, 'attachment_url' => $m->attachment_url,
                     'sender_name' => $m->sender_id ? $this->name((int) $m->sender_id) : null, 'sent_at' => $m->created_at];
        $exists = DB::table('team_chat_reports')->where('message_id', $mid)->where('reporter_id', $this->uid())->exists();
        abort_if($exists, 422, 'You already reported this message.');
        $rid = DB::table('team_chat_reports')->insertGetId(['company_id' => $this->cid(), 'chat_id' => $m->chat_id, 'message_id' => $mid,
            'reporter_id' => $this->uid(), 'reported_user_id' => $m->sender_id, 'reason' => $d['reason'], 'note' => $d['note'] ?? null,
            'snapshot' => json_encode($snapshot), 'created_at' => now(), 'updated_at' => now()]);

        // Tell the company's moderators.
        try {
            $svc = app(\App\Services\FirebasePushService::class);
            $me = $this->name($this->uid());
            DB::table('users')->where('company_id', $this->cid())->where('is_active', true)->where('id', '!=', $this->uid())->limit(300)->get()
                ->filter(fn ($u) => ($usr = \App\Models\User::find($u->id)) && ($usr->isOwner() || $usr->hasPermission('staff.manage')))
                ->each(fn ($u) => $svc->sendToUser((int) $u->id, 'team_chat_report', 'Chat message reported', "{$me} reported a message ({$d['reason']})", ['action' => 'open_team_chat_reports', 'report_id' => (string) $rid]));
        } catch (\Throwable $e) { \Log::debug('report push failed: ' . $e->getMessage()); }
        return response()->json(['ok' => true], 201);
    }

    /** GET /team-chat/admin/reports?status=open|dismissed|actioned|all — moderators only. */
    public function reports(Request $r): JsonResponse
    {
        $this->moderatorOnly();
        $status = $r->query('status', 'open');
        $rows = DB::table('team_chat_reports as r')->join('team_chats as c', 'c.id', '=', 'r.chat_id')
            ->leftJoin('users as rep', 'rep.id', '=', 'r.reporter_id')->leftJoin('users as sen', 'sen.id', '=', 'r.reported_user_id')
            ->where('r.company_id', $this->cid())->when($status !== 'all', fn ($q) => $q->where('r.status', $status))
            ->orderByDesc('r.id')->limit(100)
            ->get(['r.*', 'c.name as chat_name', 'c.type as chat_type', 'rep.name as reporter_name', 'sen.name as sender_name']);
        return response()->json(['data' => $rows->map(fn ($x) => [
            'id' => $x->id, 'chat_id' => $x->chat_id, 'chat_name' => $x->chat_type === 'group' ? $x->chat_name : 'Direct chat', 'chat_type' => $x->chat_type,
            'message_id' => $x->message_id, 'reporter_name' => $x->reporter_name, 'sender_id' => $x->reported_user_id, 'sender_name' => $x->sender_name,
            'reason' => $x->reason, 'note' => $x->note, 'snapshot' => json_decode($x->snapshot ?? 'null', true), 'status' => $x->status, 'action' => $x->action,
            'created_at' => $x->created_at, 'handled_at' => $x->handled_at])]);
    }

    /** POST /team-chat/admin/reports/{id}/resolve {action: dismiss|delete_message|remove_sender|delete_and_remove} */
    public function resolveReport(Request $r, int $id): JsonResponse
    {
        $this->moderatorOnly();
        $action = $r->validate(['action' => 'required|in:dismiss,delete_message,remove_sender,delete_and_remove'])['action'];
        $rep = DB::table('team_chat_reports')->where('id', $id)->where('company_id', $this->cid())->first();
        abort_unless($rep, 404);
        if (in_array($action, ['delete_message', 'delete_and_remove'], true)) {
            DB::table('team_chat_messages')->where('id', $rep->message_id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            $this->emit((int) $rep->chat_id, 'delete', ['message_id' => (int) $rep->message_id]);
        }
        if (in_array($action, ['remove_sender', 'delete_and_remove'], true) && $rep->reported_user_id) {
            $type = DB::table('team_chats')->where('id', $rep->chat_id)->value('type');
            abort_unless($type === 'group', 422, 'You can only remove someone from a group.');
            DB::table('team_chat_members')->where('chat_id', $rep->chat_id)->where('user_id', $rep->reported_user_id)->update(['left_at' => now(), 'updated_at' => now()]);
            $this->system((int) $rep->chat_id, $this->name((int) $rep->reported_user_id) . ' was removed by a company admin');
        }
        DB::table('team_chat_reports')->where('id', $id)->update(['status' => $action === 'dismiss' ? 'dismissed' : 'actioned', 'action' => $action,
            'handled_by' => $this->uid(), 'handled_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** GET /team-chat/admin/groups — every group in the company (even ones the admin is not in). */
    public function adminGroups(): JsonResponse
    {
        $this->moderatorOnly();
        $rows = DB::table('team_chats as c')->leftJoin('users as u', 'u.id', '=', 'c.created_by')->where('c.company_id', $this->cid())->where('c.type', 'group')
            ->orderByRaw('COALESCE(c.last_message_at, c.created_at) DESC')->limit(200)->get(['c.id', 'c.name', 'c.avatar_url', 'c.last_message_at', 'c.created_at', 'u.name as creator']);
        $counts = DB::table('team_chat_members')->whereIn('chat_id', $rows->pluck('id'))->selectRaw('chat_id, SUM(left_at IS NULL) as active, SUM(left_at IS NOT NULL) as former')->groupBy('chat_id')->get()->keyBy('chat_id');
        $open = DB::table('team_chat_reports')->whereIn('chat_id', $rows->pluck('id'))->where('status', 'open')->groupBy('chat_id')->selectRaw('chat_id, count(*) as c')->pluck('c', 'chat_id');
        return response()->json(['data' => $rows->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name, 'avatar' => $g->avatar_url, 'creator' => $g->creator, 'last_message_at' => $g->last_message_at, 'created_at' => $g->created_at,
            'members' => (int) ($counts[$g->id]->active ?? 0), 'former_members' => (int) ($counts[$g->id]->former ?? 0), 'open_reports' => (int) ($open[$g->id] ?? 0)])]);
    }

    /** GET /team-chat/admin/groups/{id} — members + people who left. */
    public function adminGroup(int $id): JsonResponse
    {
        $this->moderatorOnly();
        $chat = DB::table('team_chats')->where('id', $id)->where('company_id', $this->cid())->where('type', 'group')->first();
        abort_unless($chat, 404);
        $rows = DB::table('team_chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.chat_id', $id)
            ->orderByRaw('m.left_at IS NULL DESC')->orderBy('u.name')->get(['u.id', 'u.name', 'u.avatar', 'm.role', 'm.left_at']);
        return response()->json(['id' => $id, 'name' => $chat->name, 'avatar' => $chat->avatar_url, 'only_admins_post' => (bool) $chat->only_admins_post,
            'members' => $rows->whereNull('left_at')->values(), 'former_members' => $rows->whereNotNull('left_at')->values()]);
    }

    /** DELETE /team-chat/admin/groups/{id}/members/{userId} — moderator removes someone from any group. */
    public function adminRemoveMember(int $id, int $userId): JsonResponse
    {
        $this->moderatorOnly();
        abort_unless(DB::table('team_chats')->where('id', $id)->where('company_id', $this->cid())->where('type', 'group')->exists(), 404);
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $userId)->update(['left_at' => now(), 'updated_at' => now()]);
        $left = DB::table('team_chat_members')->where('chat_id', $id)->whereNull('left_at');
        if ((clone $left)->count() && !(clone $left)->where('role', 'admin')->exists()) {
            DB::table('team_chat_members')->where('id', (clone $left)->orderBy('joined_at')->value('id'))->update(['role' => 'admin']);
        }
        $this->system($id, $this->name($userId) . ' was removed by a company admin');
        return response()->json(['ok' => true]);
    }

    /** DELETE /team-chat/admin/groups/{id} — moderator deletes any group. */
    public function adminDeleteGroup(int $id): JsonResponse
    {
        $this->moderatorOnly();
        abort_unless(DB::table('team_chats')->where('id', $id)->where('company_id', $this->cid())->where('type', 'group')->exists(), 404);
        DB::table('team_chats')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
