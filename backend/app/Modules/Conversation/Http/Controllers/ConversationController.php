<?php

namespace App\Modules\Conversation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Modules\Conversation\Events\WaMessageReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    // GET /conversations — inbox list, most recently active first
    public function index(Request $request): JsonResponse
    {
        $user      = auth()->user();
        $companyId = $user->company_id;
        $allowedNumbers = $user->allowedAccountIds('phone_number');

        // Conversation access follows Lead assignment (LeadAssignmentEngine::syncConversationOwner
        // keeps assigned_to in step with it) — a regular agent only sees conversations assigned to
        // them or not yet assigned to anyone, never a coworker's. inbox.view_all (owner/admin/team
        // lead by default) lifts this to every conversation, same permission send() already uses.
        $canViewAll = $user->hasPermission('inbox.view_all');

        // What this person may see at all (their company, their numbers, their own or unassigned chats).
        $visible = fn () => WaConversation::where('company_id', $companyId)
            ->when($allowedNumbers !== null, fn ($q) => $q->whereIn('wa_phone_number_id', $allowedNumbers))
            ->when(!$canViewAll, fn ($q) => $q->where(fn ($q2) => $q2->where('assigned_to', $user->id)->orWhereNull('assigned_to')));
        // "Unassigned" is the queue of customers waiting for someone: only chats where the customer has actually written.
        // A number we only sent a campaign template to (nothing back yet) is not waiting for anybody, so it stays out.
        $unassigned = fn ($q) => $q->whereNull('assigned_to')
            ->whereExists(fn ($m) => $m->selectRaw('1')->from('wa_messages')
                ->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')->where('wa_messages.direction', 'inbound'));

        // Status tabs: All · Unread (open, customer's messages waiting) · Read (open, nothing waiting) · Closed. They split the whole list, so
        // Unread + Read + Closed = All.
        $state = in_array($request->query('state'), ['unread', 'read', 'closed'], true) ? $request->query('state') : 'all';
        $notClosed = fn ($q) => $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'closed'));
        $inState = fn ($q, string $st) => match ($st) {
            'unread' => $notClosed($q)->where('unread_count', '>', 0),
            'read'   => $notClosed($q)->where('unread_count', 0),
            'closed' => $q->where('status', 'closed'),
            default  => $q,
        };

        $conversations = $visible()
            ->when($state !== 'all', fn ($q) => $inState($q, $state))
            ->withExists(['stars as starred' => fn ($s) => $s->where('user_id', $user->id)])   // this user's own star
            ->when($request->boolean('starred'), fn ($q) => $q->whereHas('stars', fn ($s) => $s->where('user_id', $user->id)))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($request->filled('wa_phone_number_id'), fn ($q) => $q->where('wa_phone_number_id', $request->integer('wa_phone_number_id')))
            ->when($request->boolean('unassigned'), $unassigned)
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_count', '>', 0))
            // server-side search, so a customer beyond the first page can still be found
            ->when(trim((string) $request->search) !== '', function ($q) use ($request) {
                $term = '%' . trim((string) $request->search) . '%';
                $q->where(fn ($w) => $w->where('contact_name', 'like', $term)->orWhere('phone', 'like', $term)
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->with([
                'assignedAgent:id,name',
                'contact:id,name',
                // Which of the company's WA Cloud numbers this conversation is on — a company
                // running several numbers had no way to tell them apart in the inbox list.
                'waPhoneNumber:id,label,display_number',
                'lastMessage',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')   // stable order, so page 2 never repeats or skips rows with equal timestamps
            ->paginate(min(max($request->integer('per_page', 30), 10), 100));

        // Totals for the tab badges (same visibility rules; not narrowed by the search box)
        $counts = [
            'all'        => $visible()->when($request->status, fn ($q, $s) => $q->where('status', $s))->count(),
            'unread'     => $visible()->where('unread_count', '>', 0)->count(),
            'starred'    => $visible()->whereHas('stars', fn ($s) => $s->where('user_id', $user->id))->count(),
            'mine'       => $visible()->where('assigned_to', $user->id)->count(),
            'unassigned' => $visible()->tap($unassigned)->count(),
            // total unread MESSAGES (the Unread tab counts chats; this is how many messages are waiting in them)
            'unread_messages' => (int) $visible()->sum('unread_count'),
        ];

        // Counts for the status tabs: within the same scope as the list (All chats / Assigned to me, the number and the Unassigned filter),
        // not narrowed by the search box or by the status tab itself.
        $scoped = fn () => $visible()
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($request->filled('wa_phone_number_id'), fn ($q) => $q->where('wa_phone_number_id', $request->integer('wa_phone_number_id')))
            ->when($request->boolean('unassigned'), $unassigned);
        $stateCounts = [
            'all'    => $scoped()->count(),
            'unread' => $inState($scoped(), 'unread')->count(),
            'read'   => $inState($scoped(), 'read')->count(),
            'closed' => $inState($scoped(), 'closed')->count(),
        ];

        return response()->json([
            'counts'        => $counts,
            'state_counts'  => $stateCounts,
            'conversations' => $conversations->items(),
            'total'         => $conversations->total(),
            'page'          => $conversations->currentPage(),
            'has_more'      => $conversations->hasMorePages(),
        ]);
    }

    // GET /conversations/contact/{contact} — this contact's chats (one per WhatsApp number), newest first, so a contact
    // opened from anywhere (a lead category, the CRM) can show its conversation. Same access rule as index().
    public function forContact(int $contact): JsonResponse
    {
        $user = auth()->user();
        // a campaign number may not be a CRM contact yet: contact 0 + ?phone= finds the chat by number instead
        $digits = preg_replace('/\D+/', '', (string) request('phone', ''));
        $rows = WaConversation::where('company_id', $user->company_id)
            ->when($contact > 0, fn ($q) => $q->where('contact_id', $contact), fn ($q) => $q->where('phone', $digits ?: '__none__'))
            ->when($user->allowedAccountIds('phone_number') !== null, fn ($q) => $q->whereIn('wa_phone_number_id', $user->allowedAccountIds('phone_number')))
            ->when(!$user->hasPermission('inbox.view_all'), fn ($q) => $q->where(fn ($q2) => $q2->where('assigned_to', $user->id)->orWhereNull('assigned_to')))
            ->with('waPhoneNumber:id,label,display_number')
            ->orderByDesc('last_message_at')->get();

        // When the customer last wrote — WhatsApp lets us send free text for 24 hours after that; later it has to be a template.
        $lastIn = \App\Models\WaMessage::whereIn('conversation_id', $rows->pluck('id'))->where('direction', 'inbound')
            ->selectRaw('conversation_id, MAX(created_at) last_in')->groupBy('conversation_id')->pluck('last_in', 'conversation_id');

        return response()->json(['conversations' => $rows->map(fn ($c) => [
            'id' => $c->id, 'phone' => $c->phone, 'status' => $c->status, 'unread_count' => $c->unread_count,
            'last_message_at' => $c->last_message_at, 'number' => $c->waPhoneNumber?->label ?: $c->waPhoneNumber?->display_number,
            'wa_phone_number_id' => $c->wa_phone_number_id,
            'last_inbound_at' => ($t = $lastIn[$c->id] ?? null) ? \Illuminate\Support\Carbon::parse($t)->toIso8601String() : null,
            'window_open' => $t ? \Illuminate\Support\Carbon::parse($t)->gt(now()->subHours(24)) : false,
        ])->values()]);
    }

    // GET /conversations/{id}/messages — full thread, oldest first
    public function messages(int $id): JsonResponse
    {
        $user = auth()->user();

        // Same access rule as index(): reachable by id directly, so this must enforce it too,
        // not just the list filter.
        $conversation = WaConversation::where('id', $id)
            ->where('company_id', $user->company_id)
            ->when(!$user->hasPermission('inbox.view_all'), fn ($q) => $q->where(fn ($q2) => $q2->where('assigned_to', $user->id)->orWhereNull('assigned_to')))
            ->with([
                'assignedAgent:id,name',
                'contact:id,name,phone,email,lead_stage,lead_score,conversation_summary',
                'waPhoneNumber:id,label,display_number',
            ])
            ->firstOrFail();

        // A long-running thread can hold thousands of rows, so it is served in pages: the newest `limit`
        // messages first, then older ones on request with `before_id` (the id of the oldest message the
        // client already has). Each page is handed back oldest-first for display; `has_more` says whether
        // there is anything earlier still.
        $limit  = min(max((int) request('limit', 50), 20), 200);
        $before = (int) request('before_id', 0);

        $rows = WaMessage::where('conversation_id', $conversation->id)
            ->with('sentBy:id,name')
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore  = $rows->count() > $limit;
        $messages = $rows->take($limit)->reverse()->values();

        // Opening the thread marks it read (loading older history does not need to). A read-only peek — a chat preview on a
        // lead or campaign page — passes ?peek=1 so looking at it does not clear the agents' unread count.
        if ($before === 0 && !request()->boolean('peek')) {
            $conversation->update(['unread_count' => 0]);
        }

        // WhatsApp lets us send free text for 24 hours after the customer's last message; after that only an approved template.
        $lastIn = WaMessage::where('conversation_id', $conversation->id)->where('direction', 'inbound')->max('created_at');
        $lastInAt = $lastIn ? \Illuminate\Support\Carbon::parse($lastIn) : null;

        $conversation->setAttribute('starred', $conversation->stars()->where('user_id', $user->id)->exists());

        return response()->json(['conversation' => $conversation, 'messages' => $messages, 'has_more' => $hasMore,
            'last_inbound_at' => $lastInAt?->toIso8601String(), 'window_open' => $lastInAt ? $lastInAt->gt(now()->subHours(24)) : false]);
    }

    /** Meta's own wording of why a send failed — with a plain explanation for the 24-hour rule (error 131047 "re-engagement"). */
    private function metaFailure($response): string
    {
        $reason = $response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta API error';
        if ($this->isWindowError($response)) {
            return 'WhatsApp did not accept this: more than 24 hours have passed since the customer last wrote, and Meta only allows an approved template message then. Send a template, or wait for the customer to reply.';
        }
        return $reason;
    }

    private function isWindowError($response): bool
    {
        return (int) $response->json('error.code') === 131047;
    }

    // POST /conversations/{id}/media — send a photo, video, audio file or document (multipart: file, caption?). Allowed at any time; after
    // 24 hours WhatsApp itself decides whether to accept it (see metaFailure()).
    public function sendMedia(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:16384'], 'caption' => ['nullable', 'string', 'max:1024']]);   // 16 MB
        $user = auth()->user();
        $conversation = $this->visibleConversation($id)->load('waPhoneNumber');
        if (!$user->hasPermission('inbox.view_all') && (int) $conversation->assigned_to !== (int) $user->id) {
            return response()->json(['message' => $conversation->assigned_to ? 'This conversation is assigned to another agent.' : 'Claim this conversation before replying.'], 403);
        }
        $company = $user->company;
        $number = $conversation->waPhoneNumber;
        if (!$number || !$company->decrypt_wa_access_token) {
            return response()->json(['message' => 'WhatsApp credentials not configured for this number.'], 422);
        }

        $file = $request->file('file');
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $type = str_starts_with($mime, 'image/') && in_array($mime, ['image/jpeg', 'image/png'], true) ? 'image'
            : (str_starts_with($mime, 'video/') && in_array($mime, ['video/mp4', 'video/3gpp'], true) ? 'video'
            : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
        $path = $file->store('wa-outbound/' . $company->id, 'public');
        $url = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        if (!preg_match('#^https?://#i', $url)) { $url = url($url); }
        $caption = trim((string) ($d['caption'] ?? ''));
        $name = $file->getClientOriginalName();

        $message = WaMessage::create([
            'conversation_id' => $conversation->id, 'company_id' => $company->id, 'direction' => 'outbound', 'sender_type' => 'agent', 'sent_by' => $user->id,
            'type' => $type, 'content' => ['body' => $caption, 'media' => ['url' => $url, 'mime' => $mime, 'mimetype' => $mime, 'filename' => $name, 'size' => $file->getSize()]],
            'status' => 'queued',
        ]);
        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        $payload = ['messaging_product' => 'whatsapp', 'to' => $conversation->phone, 'type' => $type,
            $type => array_filter(['link' => $url, 'caption' => $type === 'audio' ? null : ($caption ?: null), 'filename' => $type === 'document' ? $name : null])];
        try {
            $response = Http::withToken($company->decrypt_wa_access_token)->timeout(30)->post("https://graph.facebook.com/v21.0/{$number->phone_number_id}/messages", $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $message->update(['status' => 'failed', 'failure_reason' => 'Timed out contacting Meta.']);
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => 'Timed out sending the file. It has been marked failed — you can retry.'], 422);
        }
        if ($response->failed()) {
            $reason = $this->metaFailure($response);
            $message->update(['status' => 'failed', 'failure_reason' => $reason]);
            \App\Models\MessageLog::recordCloudOutbound($company->id, $conversation->phone, $type, $caption !== '' ? $caption : "[{$type}]", 'inbox', null, $reason);
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => $reason, 'window_closed' => $this->isWindowError($response)], 422);
        }
        $message->update(['wa_message_id' => $response->json('messages.0.id'), 'status' => 'sent', 'status_updated_at' => now()]);
        \App\Models\MessageLog::recordCloudOutbound($company->id, $conversation->phone, $type, $caption !== '' ? $caption : "[{$type}]", 'inbox', $response->json('messages.0.id'));
        $conversation->update(['last_message_at' => now()]);
        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        return response()->json(['message' => $message->fresh()]);
    }

    // PUT /conversations/{id}/star { starred: bool } — a personal star on a chat (only you see it)
    public function star(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['starred' => ['required', 'boolean']]);
        $user = auth()->user();
        $conversation = $this->visibleConversation($id);
        if ($d['starred']) {
            \App\Models\WaConversationStar::firstOrCreate(['user_id' => $user->id, 'conversation_id' => $conversation->id], ['company_id' => $user->company_id]);
        } else {
            \App\Models\WaConversationStar::where('user_id', $user->id)->where('conversation_id', $conversation->id)->delete();
        }
        return response()->json(['starred' => (bool) $d['starred']]);
    }

    // POST /conversations/{id}/close — finish with this customer for now. A new message from them reopens the chat by itself.
    public function close(int $id): JsonResponse
    {
        return $this->setStatus($id, 'closed');
    }

    // POST /conversations/{id}/reopen
    public function reopen(int $id): JsonResponse
    {
        return $this->setStatus($id, 'open');
    }

    /** A conversation this user may see (same rule as the list / thread): their company, their numbers, their own or unassigned chats. */
    private function visibleConversation(int $id): WaConversation
    {
        $user = auth()->user();
        $allowed = $user->allowedAccountIds('phone_number');
        return WaConversation::where('id', $id)->where('company_id', $user->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('wa_phone_number_id', $allowed))
            ->when(!$user->hasPermission('inbox.view_all'), fn ($q) => $q->where(fn ($q2) => $q2->where('assigned_to', $user->id)->orWhereNull('assigned_to')))
            ->firstOrFail();
    }

    private function setStatus(int $id, string $status): JsonResponse
    {
        $user = auth()->user();
        $conversation = $this->visibleConversation($id);
        // like replying: the assigned agent, or anyone who may handle every chat (inbox.view_all)
        if (!$user->hasPermission('inbox.view_all') && (int) $conversation->assigned_to !== (int) $user->id) {
            return response()->json(['message' => 'Only the agent this chat is assigned to, or a team leader, can ' . ($status === 'closed' ? 'close' : 'reopen') . ' it.'], 403);
        }
        $conversation->update(['status' => $status]);
        return response()->json(['message' => $status === 'closed' ? 'Conversation closed.' : 'Conversation reopened.', 'conversation' => $conversation->fresh()]);
    }

    // POST /conversations/{id}/claim — atomic first-come-first-served assignment,
    // so two counsellors can't both end up "assigned" to the same customer.
    public function claim(int $id): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $claimed = DB::table('wa_conversations')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('assigned_to')
            ->update(['assigned_to' => auth()->id(), 'updated_at' => now()]);

        if (!$claimed) {
            $conversation = WaConversation::find($id);
            return response()->json([
                'message' => $conversation && $conversation->assigned_to
                    ? 'Already claimed by ' . ($conversation->assignedAgent->name ?? 'another agent') . '.'
                    : 'Conversation not found.',
            ], 409);
        }

        return response()->json(['message' => 'Conversation assigned to you.', 'conversation' => WaConversation::find($id)]);
    }

    // POST /conversations/{id}/release — give it back to the unassigned pool
    public function release(int $id): JsonResponse
    {
        $user = auth()->user();
        // Was in_array($user->role, ['admin', 'team_leader'], true) — $user->role is the
        // Role *model* (a BelongsTo relation), never equal to a string under strict
        // comparison, and 'team_leader' doesn't match the real role name ('team_lead').
        // Net effect: this override never actually fired; only the exact assignee could
        // release a conversation. Driven by the real permission now instead of a role-name list.
        $canManageAny = $user->hasPermission('inbox.view_all');

        $conversation = WaConversation::where('id', $id)
            ->where('company_id', $user->company_id)
            ->when(!$canManageAny, fn ($q) => $q->where('assigned_to', $user->id))
            ->firstOrFail();

        $conversation->update(['assigned_to' => null]);
        return response()->json(['message' => 'Conversation released.']);
    }

    // POST /conversations/{id}/messages — counsellor sends a reply
    public function send(Request $request, int $id): JsonResponse
    {


     $graphVersion = 'v21.0';

        $d = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        $conversation = WaConversation::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->with('waPhoneNumber')
            ->firstOrFail();

        $user = auth()->user();

        // Assigned agent can reply to their own conversation. Anyone with inbox.view_all
        // (owner/admin/team_lead by default) can reply to ANY conversation regardless of
        // assignment — a supervising override, not something a regular agent gets.
        $canReplyToAny = $user->hasPermission('inbox.view_all');
        $isAssignedToMe = $conversation->assigned_to === $user->id;

        if (!$canReplyToAny && !$isAssignedToMe) {
            return response()->json([
                'message' => $conversation->assigned_to
                    ? 'This conversation is assigned to another agent. Ask them, or an admin/team leader, to reply.'
                    : 'Claim this conversation before replying.',
            ], 403);
        }

        // No window check of our own: staff may reply any time. After 24 hours WhatsApp itself may refuse a non-template message —
        // that answer is passed back below (with a hint to send a template) instead of being blocked here.

        $company = auth()->user()->company;
        $phone   = $conversation->waPhoneNumber;

        if (!$phone || !$company->decrypt_wa_access_token) {
            return response()->json(['message' => 'WhatsApp credentials not configured for this number.'], 422);
        }

        // Log as "queued" first so it shows in the thread immediately, then update
        // once Meta responds — the agent sees their own message without waiting on the API call.
        $message = WaMessage::create([
            'conversation_id' => $conversation->id,
            'company_id'      => $company->id,
            'direction'       => 'outbound',
            'sender_type'     => 'agent',
            'sent_by'         => auth()->id(),
            'type'            => 'text',
            'content'         => ['body' => $d['body']],
            'status'          => 'queued',
        ]);

        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        try {

            $response = Http::withToken($company->decrypt_wa_access_token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$graphVersion}/{$phone->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $conversation->phone,
                    'type'              => 'text',
                    'text'              => ['body' => $d['body']],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::info($e);
            $message->update(['status' => 'failed', 'failure_reason' => 'Timed out contacting Meta.']);
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => 'Timed out sending the message. It has been marked failed — you can retry.'], 422);
        }

        if ($response->failed()) {
               Log::info($response);
            $reason = $this->metaFailure($response);
            $message->update(['status' => 'failed', 'failure_reason' => $reason]);
            \App\Models\MessageLog::recordCloudOutbound($company->id, $conversation->phone, 'text', $d['body'], 'inbox', null, $reason);
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => $reason, 'window_closed' => $this->isWindowError($response)], 422);
        }

        $message->update([
            'wa_message_id' => $response->json('messages.0.id'),
            'status'        => 'sent',
            'status_updated_at' => now(),
        ]);

        \App\Models\MessageLog::recordCloudOutbound($company->id, $conversation->phone, 'text', $d['body'], 'inbox', $response->json('messages.0.id'));

        $conversation->update(['last_message_at' => now()]);
        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        return response()->json(['message' => $message->fresh()]);
    }

    // POST /conversations/new — start a chat with a number that has not written to us (or whose 24-hour window has
    // closed). WhatsApp only allows this with an approved TEMPLATE, so the agent picks the sending number, the
    // recipient and a template; the thread is created (or reused), assigned to the sender and the template is sent.
    public function startChat(Request $request, \App\Modules\Wallet\Services\WalletService $wallet): JsonResponse
    {
        $d = $request->validate([
            'wa_phone_number_id' => ['required', 'integer'],
            'phone'              => ['required', 'string', 'max:30'],
            'contact_id'         => ['nullable', 'integer'],
            'template_id'        => ['required', 'integer'],
            'variables'          => ['nullable', 'array'],
            'variables.*'        => ['nullable', 'string', 'max:500'],
        ]);

        $user    = auth()->user();
        $company = $user->company;

        $allowed = $user->allowedAccountIds('phone_number');
        $number  = \App\Models\WaPhoneNumber::where('company_id', $company->id)->whereKey($d['wa_phone_number_id'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->first();
        if (!$number || !$number->is_active || !$number->access_token || !$number->phone_number_id) {
            return response()->json(['message' => 'That sending number is not available or has no credentials.'], 422);
        }

        $template = \App\Models\WaTemplate::where('company_id', $company->id)->find($d['template_id']);
        if (!$template || strtolower((string) $template->status) !== 'approved') {
            return response()->json(['message' => 'Choose an approved template — WhatsApp only lets you start a chat with one.'], 422);
        }

        $phone = preg_replace('/\D+/', '', $d['phone']);
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            return response()->json(['message' => 'Enter the number with its country code, e.g. 919876543210.'], 422);
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('message_blacklist')
            && DB::table('message_blacklist')->where('company_id', $company->id)->where('phone', $phone)->exists()) {
            return response()->json(['message' => 'This number is on your blacklist.'], 422);
        }

        $variables = array_values($d['variables'] ?? []);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $template->body, $m);
        $needed = $m[1] ? max(array_map('intval', $m[1])) : 0;
        if ($needed > count(array_filter($variables, fn ($v) => trim((string) $v) !== ''))) {
            return response()->json(['message' => "This template needs {$needed} variable value(s)."], 422);
        }

        $billed = $wallet->usesWallet($company);
        if ($billed && $wallet->getWallet($company->id)->balance < 1) {
            return response()->json(['message' => 'Not enough wallet balance to send this message.'], 402);
        }

        // One thread per customer number: reuse it (and move it to this sending number), otherwise open a new one.
        $contactId = !empty($d['contact_id'])
            ? \App\Models\Contact::where('company_id', $company->id)->whereKey($d['contact_id'])->value('id')
            : \App\Models\Contact::where('company_id', $company->id)->where('phone', $phone)->value('id');
        $conversation = WaConversation::firstOrNew(['company_id' => $company->id, 'phone' => $phone]);
        if ($conversation->exists && !$user->hasPermission('inbox.view_all') && $conversation->assigned_to && $conversation->assigned_to !== $user->id) {
            return response()->json(['message' => 'This number already has a chat assigned to another agent.'], 403);
        }
        $conversation->fill([
            'wa_phone_number_id' => $number->id,
            'contact_id'         => $conversation->contact_id ?: $contactId,
            'status'             => 'open',
            'assigned_to'        => $conversation->assigned_to ?: $user->id,
        ]);
        if (!$conversation->exists) { $conversation->unread_count = 0; }
        $conversation->save();

        // Rendered text for the thread (the customer sees the real template; we show the same words).
        $rendered = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn ($mm) => $variables[(int) $mm[1] - 1] ?? $mm[0], (string) $template->body);
        $message = WaMessage::create([
            'conversation_id' => $conversation->id,
            'company_id'      => $company->id,
            'direction'       => 'outbound',
            'sender_type'     => 'agent',
            'sent_by'         => $user->id,
            'type'            => 'template',
            'content'         => ['body' => $rendered, 'template' => $template->name],
            'status'          => 'queued',
        ]);

        $result = app(\App\Modules\Campaign\Services\CampaignMessageSender::class)
            ->send($number->decrypted_token, $number->phone_number_id, $phone, $template, $variables);

        if (!$result['ok']) {
            $reason = $result['reason'] ?? 'Meta rejected the message.';
            $message->update(['status' => 'failed', 'failure_reason' => $reason]);
            \App\Models\MessageLog::recordCloudOutbound($company->id, $phone, 'template', $rendered, 'inbox', null, $reason, null, $template->name);
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => $reason, 'conversation' => $conversation->fresh()], 422);
        }

        $message->update(['wa_message_id' => $result['wa_message_id'], 'status' => 'sent', 'status_updated_at' => now()]);
        $conversation->update(['last_message_at' => now()]);
        \App\Models\MessageLog::recordCloudOutbound($company->id, $phone, 'template', $rendered, 'inbox', $result['wa_message_id'], null, null, $template->name);
        // Charged when WhatsApp reports it delivered (WaCloudBilling), at the company's rate for the template's category.
        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        return response()->json(['conversation' => $conversation->fresh(['waPhoneNumber']), 'message' => $message->fresh()], 201);
    }
}
