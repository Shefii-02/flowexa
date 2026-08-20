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
        $companyId = auth()->user()->company_id;

        $conversations = WaConversation::where('company_id', $companyId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assigned_to'))
            ->with(['assignedAgent:id,name'])
            ->orderByDesc('last_message_at')
            ->paginate($request->integer('per_page', 30));

        return response()->json([
            'conversations' => $conversations->items(),
            'total'         => $conversations->total(),
        ]);
    }

    // GET /conversations/{id}/messages — full thread, oldest first
    public function messages(int $id): JsonResponse
    {
        $conversation = WaConversation::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $messages = WaMessage::where('conversation_id', $conversation->id)
            ->with('sentBy:id,name')
            ->orderBy('created_at')
            ->get();

        // Opening the thread marks it read
        $conversation->update(['unread_count' => 0]);

        return response()->json(['conversation' => $conversation, 'messages' => $messages]);
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
        $canManageAny = in_array($user->role, ['admin', 'team_leader'], true);

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

        // Assigned agent can reply to their own conversation. Admin/team_leader can
        // reply to ANY conversation regardless of assignment — a supervising override,
        // not something a regular agent gets. Adjust the role check to match your
        // actual role names/enum if they differ from 'admin'/'team_leader'.
        $canReplyToAny = in_array($user->role, ['admin', 'team_leader'], true);
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
                ->post("https://graph.facebook.com/{$graphVersion}/{$phone->wa_phone_number_id}/messages", [
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
            broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));
            return response()->json(['message' => $reason], 422);
        }

        $message->update([
            'wa_message_id' => $response->json('messages.0.id'),
            'status'        => 'sent',
            'status_updated_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
        broadcast(new WaMessageReceived($message->fresh(['conversation', 'sentBy'])));

        return response()->json(['message' => $message->fresh()]);
    }
}
