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
            ->where('c.company_id', $this->cid())->select('m.*', 'c.type', 'c.only_admins_post', 'c.name as chat_name')->first();
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
    public function chats(): JsonResponse
    {
        $uid = $this->uid();
        $rows = DB::table('team_chat_members as m')->join('team_chats as c', 'c.id', '=', 'm.chat_id')
            ->where('m.user_id', $uid)->whereNull('m.left_at')->where('c.company_id', $this->cid())
            ->orderByRaw('COALESCE(c.last_message_at, c.created_at) DESC')
            ->get(['c.*', 'm.last_read_message_id', 'm.muted', 'm.role as my_role']);

        $ids = $rows->pluck('id');
        $unread = DB::table('team_chat_messages as msg')->join('team_chat_members as m', function ($j) use ($uid) {
                $j->on('m.chat_id', '=', 'msg.chat_id')->where('m.user_id', $uid);
            })->whereIn('msg.chat_id', $ids)->whereNull('msg.deleted_at')
            ->whereColumn('msg.id', '>', 'm.last_read_message_id')->where(fn ($q) => $q->whereNull('msg.sender_id')->orWhere('msg.sender_id', '!=', $uid))
            ->groupBy('msg.chat_id')->select('msg.chat_id', DB::raw('count(*) as c'))->pluck('c', 'chat_id');
        $last = DB::table('team_chat_messages')->whereIn('id', $rows->pluck('last_message_id')->filter())->get()->keyBy('id');
        $counts = DB::table('team_chat_members')->whereIn('chat_id', $ids)->whereNull('left_at')->groupBy('chat_id')->select('chat_id', DB::raw('count(*) as c'))->pluck('c', 'chat_id');
        $others = DB::table('team_chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.chat_id', $rows->where('type', 'direct')->pluck('id'))->where('m.user_id', '!=', $uid)
            ->get(['m.chat_id', 'u.id as uid', 'u.name', 'u.avatar'])->keyBy('chat_id');

        $data = $rows->map(function ($c) use ($unread, $last, $counts, $others) {
            $o = $others[$c->id] ?? null; $l = $last[$c->last_message_id] ?? null;
            return [
                'id' => $c->id, 'type' => $c->type,
                'name' => $c->type === 'direct' ? ($o->name ?? 'Chat') : $c->name,
                'avatar' => $c->type === 'direct' ? ($o->avatar ?? null) : $c->avatar_url,
                'other_user_id' => $o->uid ?? null,
                'members_count' => (int) ($counts[$c->id] ?? 0), 'muted' => (bool) $c->muted, 'my_role' => $c->my_role,
                'unread' => (int) ($unread[$c->id] ?? 0),
                'last_message' => $l ? $this->preview($l) : null, 'last_message_at' => $c->last_message_at,
            ];
        });
        return response()->json(['data' => $data]);
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
            'only_admins_post' => (bool) $chat->only_admins_post, 'my_role' => $me->role, 'muted' => (bool) $me->muted,
            'created_by' => $chat->created_by, 'members' => $members]);
    }

    /** PATCH /team-chat/chats/{id} — rename / only-admins-post (group admins). */
    public function update(Request $r, int $id): JsonResponse
    {
        $me = $this->member($id); $this->adminOnly($me);
        $d = $r->validate(['name' => 'sometimes|string|max:80', 'only_admins_post' => 'sometimes|boolean']);
        DB::table('team_chats')->where('id', $id)->update($d + ['updated_at' => now()]);
        if (isset($d['name'])) { $this->system($id, $this->name($this->uid()) . ' renamed the group to "' . $d['name'] . '"'); }
        if (array_key_exists('only_admins_post', $d)) {
            $this->system($id, $this->name($this->uid()) . ($d['only_admins_post'] ? ' restricted posting to admins' : ' allowed everyone to post'));
        }
        return response()->json(['ok' => true]);
    }

    /** POST /team-chat/chats/{id}/members {user_ids[]} */
    public function addMembers(Request $r, int $id): JsonResponse
    {
        $me = $this->member($id); $this->adminOnly($me);
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

    /** POST /team-chat/chats/{id}/mute {muted} */
    public function mute(Request $r, int $id): JsonResponse
    {
        $this->member($id);
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->update(['muted' => $r->boolean('muted')]);
        return response()->json(['ok' => true]);
    }

    /** POST /team-chat/chats/{id}/read */
    public function read(int $id): JsonResponse
    {
        $this->member($id);
        $last = (int) DB::table('team_chat_messages')->where('chat_id', $id)->max('id');
        DB::table('team_chat_members')->where('chat_id', $id)->where('user_id', $this->uid())->update(['last_read_message_id' => $last]);
        $this->emit($id, 'read', ['last_id' => $last]);
        return response()->json(['ok' => true]);
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /** GET /team-chat/chats/{id}/messages?after_id=&before_id=&limit=40&q= */
    public function messages(Request $r, int $id): JsonResponse
    {
        $this->member($id);
        $limit = min(max((int) $r->query('limit', 40), 1), 100);
        $q = DB::table('team_chat_messages')->where('chat_id', $id);
        if ($r->filled('q')) { $q->whereNull('deleted_at')->where('body', 'like', '%' . $r->query('q') . '%'); }
        if ($r->filled('after_id')) {
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
        return response()->json(['data' => $this->format(DB::table('team_chat_messages')->where('chat_id', $id)->whereIn('id', $ids)->orderBy('id')->get(), $id)]);
    }

    private function format($rows, int $chatId): array
    {
        $ids = $rows->pluck('id');
        $senders = DB::table('users')->whereIn('id', $rows->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $replies = DB::table('team_chat_messages')->whereIn('id', $rows->pluck('reply_to_id')->filter())->get()->keyBy('id');
        $react = DB::table('team_chat_reactions')->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $members = DB::table('team_chat_members')->where('chat_id', $chatId)->whereNull('left_at')->get(['user_id', 'last_read_message_id']);
        $uid = $this->uid();

        return $rows->map(function ($m) use ($senders, $replies, $react, $members, $uid) {
            $rep = $m->reply_to_id ? ($replies[$m->reply_to_id] ?? null) : null;
            $seen = $members->where('user_id', '!=', $m->sender_id)->where('last_read_message_id', '>=', $m->id)->count();
            return [
                'id' => $m->id, 'type' => $m->type, 'sender_id' => $m->sender_id, 'sender_name' => $m->sender_id ? ($senders[$m->sender_id] ?? '') : null,
                'mine' => (int) $m->sender_id === $uid,
                'body' => $m->deleted_at ? null : $m->body, 'deleted' => (bool) $m->deleted_at, 'edited' => (bool) $m->edited_at,
                'attachment_url' => $m->deleted_at ? null : $m->attachment_url, 'attachment_name' => $m->attachment_name, 'attachment_size' => $m->attachment_size,
                'attachment_mime' => $m->attachment_mime, 'duration' => $m->duration_seconds, 'forwarded' => (bool) $m->forwarded,
                'latitude' => $m->latitude !== null ? (float) $m->latitude : null, 'longitude' => $m->longitude !== null ? (float) $m->longitude : null,
                'location_label' => $m->location_label, 'contact_name' => $m->contact_name, 'contact_phone' => $m->contact_phone,
                'mentions' => $m->mentions ? json_decode($m->mentions, true) : [],
                'reply_to' => $rep ? ['id' => $rep->id, 'sender_name' => $rep->sender_id ? ($senders[$rep->sender_id] ?? DB::table('users')->where('id', $rep->sender_id)->value('name')) : null,
                                      'preview' => $this->preview($rep)] : null,
                'reactions' => collect($react[$m->id] ?? [])->groupBy('emoji')->map(fn ($g, $e) => ['emoji' => $e, 'count' => $g->count(), 'mine' => $g->contains('user_id', $uid)])->values(),
                'seen_by_count' => $seen, 'created_at' => $m->created_at,
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

    /** DELETE /team-chat/messages/{mid} — sender, or a group admin. */
    public function deleteMessage(int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        $me = $this->member((int) $m->chat_id);
        abort_unless((int) $m->sender_id === $this->uid() || ($me->type === 'group' && $me->role === 'admin'), 403, 'Not allowed.');
        DB::table('team_chat_messages')->where('id', $mid)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->emit((int) $m->chat_id, 'delete', ['message_id' => $mid]);
        return response()->json(['ok' => true]);
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

    /** GET /team-chat/messages/{mid}/seen — who has read it (group "seen by"). */
    public function seen(int $mid): JsonResponse
    {
        $m = $this->ownMessage($mid);
        $rows = DB::table('team_chat_members as mem')->join('users as u', 'u.id', '=', 'mem.user_id')->where('mem.chat_id', $m->chat_id)->whereNull('mem.left_at')
            ->where('mem.user_id', '!=', $m->sender_id)->get(['u.id', 'u.name', 'u.avatar', 'mem.last_read_message_id']);
        return response()->json([
            'seen'   => $rows->filter(fn ($x) => $x->last_read_message_id >= $mid)->values()->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'avatar' => $x->avatar]),
            'unseen' => $rows->filter(fn ($x) => $x->last_read_message_id < $mid)->values()->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'avatar' => $x->avatar]),
        ]);
    }
}
