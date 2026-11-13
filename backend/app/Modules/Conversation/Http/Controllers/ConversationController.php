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

        $conversations = $visible()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', auth()->id()))
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
            'mine'       => $visible()->where('assigned_to', $user->id)->count(),
            'unassigned' => $visible()->tap($unassigned)->count(),
            // total unread MESSAGES (the Unread tab counts chats; this is how many messages are waiting in them)
            'unread_messages' => (int) $visible()->sum('unread_count'),
        ];

        return response()->json([
            'counts'        => $counts,
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

        return response()->json(['conversations' => $rows->map(fn ($c) => [
            'id' => $c->id, 'phone' => $c->phone, 'status' => $c->status, 'unread_count' => $c->unread_count,
            'last_message_at' => $c->last_message_at, 'number' => $c->waPhoneNumber?->label ?: $c->waPhoneNumber?->display_number,
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

        return response()->json(['conversation' => $conversation, 'messages' => $messages, 'has_more' => $hasMore]);
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
            $reason = $response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta API error';
            $message->update(['status' => 'failed', 'failure_reason' => $reason]);
            \App\Models\MessageLog::recordCloudOutbound($company->id, $conversation->phone, 'text', $d['body'], 'inbox', null, $reason);
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => $reason], 422);
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
        if ($billed) { $wallet->debit($company->id, 1, "Inbox template — {$template->name}", (string) $message->id, 'inbox_template'); }
        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        return response()->json(['conversation' => $conversation->fresh(['waPhoneNumber']), 'message' => $message->fresh()], 201);
    }
}
