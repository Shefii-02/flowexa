<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Modules\Lead\Services\LeadService;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * A lead's WhatsApp Chat (open-wa) conversation, read live from the chat gateway:
 *   GET {gateway}/api/sessions/{session}/messages?chatId=88630978687215@lid&limit=100
 *
 * A WA Chat contact that arrived through a privacy id ("@lid") has no phone number, so the chat can't be found from "phone" — it is
 * found from the chat id the message came from (stored on the contact as wa_id). While reading, the gateway is also asked which real
 * number that id belongs to, so the contact can be filled in with it.
 */
class LeadWaChatController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    private function base(): string
    {
        return rtrim(preg_replace('#/api/?$#', '', (string) config('services.open_wa.base_url')), '/');
    }

    /** GET /leads/{lead}/wa-chat?limit=100 */
    public function show(Request $request, int $lead): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $u = auth()->user();
        $l = $this->leads->show($lead, $u->company_id, $u->id, $u->hasPermission('leads.view_all'));
        $contact = $l->contact;
        $limit = (int) ($request->integer('limit') ?: 100);

        $empty = ['available' => false, 'chat_id' => null, 'session' => null, 'messages' => [], 'total' => 0,
                  'phone_is_real' => $contact?->phoneLooksReal() ?? false, 'resolved_phone' => null, 'phone_conflict' => null];
        if (!$contact || !config('services.open_wa.base_url') || !$u->company?->wa_chat_token) {
            return response()->json($empty);
        }

        // the session the lead came in on first, then the company's others
        $sessions = WahaSession::where('company_id', $u->company_id)
            ->orderByRaw('id = ? desc', [$l->origin_type === 'wa_session' ? (int) $l->origin_id : 0])->orderBy('id')->limit(5)->get();
        if ($sessions->isEmpty()) {
            return response()->json($empty);
        }

        $found = null;
        foreach ($this->chatCandidates($contact) as $chatId) {
            foreach ($sessions as $session) {
                $r = $this->messages($u->company->wa_chat_token, $session, $chatId, $limit);
                if ($r !== null && count($r['messages']) > 0) { $found = [$session, $chatId, $r]; break 2; }
            }
        }

        $chatId = $found[1] ?? ($contact->wa_id ?: null);
        $session = $found[0] ?? $sessions->first();
        if ($found && !$contact->wa_id) {
            $contact->forceFill(['wa_id' => $chatId])->saveQuietly();   // next time we go straight to this chat
        }

        // is there a real number behind a privacy id? The server finds it AND saves it (the same resolver the inbound job and the hourly
        // sweep use) — the page only shows the result. A number somebody else already holds is reported, never overwritten.
        $resolved = null; $conflict = null; $saved = null;
        if ($chatId && str_ends_with($chatId, '@lid') && !$contact->phoneLooksReal()) {
            $r = app(\App\Modules\WaChat\Services\RealNumberResolver::class)->resolve($contact, $session);
            if ($r['outcome'] === 'saved') {
                $saved = $r['phone'];
                $contact->refresh();
            } elseif ($r['outcome'] === 'conflict') {
                $resolved = $r['phone'];
                $conflict = isset($r['other_id']) ? ['id' => $r['other_id'], 'name' => $r['other_name'], 'deleted' => $r['other_deleted']] : null;
            }
        }

        return response()->json([
            'available'      => $found !== null,
            'chat_id'        => $chatId,
            'session'        => $session ? ['id' => $session->id, 'name' => $session->display_name ?: $session->session_name, 'session_name' => $session->session_name] : null,
            'messages'       => $found ? $found[2]['messages'] : [],
            'total'          => $found ? $found[2]['total'] : 0,
            'phone_is_real'  => $contact->phoneLooksReal(),
            'resolved_phone' => $resolved,      // only set when someone else already has that number
            'real_number_saved' => $saved,      // the real number was just found and saved on the contact
            'phone_conflict' => $conflict,
        ]);
    }

    /**
     * POST /leads/{lead}/wa-chat/send  {text, chat_id, session_id}
     * Sends a normal chat message (free, like typing in the inbox) from one of the company's sessions to the lead's WhatsApp chat.
     * chat_id / session_id must be the ones GET .../wa-chat reported — a chat id is only accepted if it belongs to this contact.
     */
    public function send(Request $request, int $lead): JsonResponse
    {
        $data = $request->validate([
            'text'       => ['required', 'string', 'max:4000'],
            'chat_id'    => ['required', 'string', 'max:100'],
            'session_id' => ['required', 'integer'],
        ]);
        $u = auth()->user();
        $l = $this->leads->show($lead, $u->company_id, $u->id, $u->hasPermission('leads.view_all'));
        $contact = $l->contact;
        $token = $u->company?->wa_chat_token;
        if (!$contact || !$token || !config('services.open_wa.base_url')) {
            return response()->json(['message' => 'WhatsApp Chat is not set up for this company.'], 409);
        }
        if (!in_array($data['chat_id'], $this->chatCandidates($contact), true)) {
            return response()->json(['message' => 'That chat does not belong to this lead.'], 422);
        }
        $session = WahaSession::where('company_id', $u->company_id)->find($data['session_id']);
        if (!$session) {
            return response()->json(['message' => 'That WhatsApp session no longer exists.'], 422);
        }

        try {
            $res = (new \App\Modules\WaChat\Services\OpenWaMessageService())
                ->sendText($session->session_name, $token, $data['chat_id'], $data['text'], null, null, 'manual');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not reach the WhatsApp gateway.'], 502);
        }
        if (!$res->successful()) {
            $msg = $res->json('message') ?: "Message not sent (gateway returned HTTP {$res->status()}).";
            return response()->json(['message' => is_string($msg) ? $msg : 'Message not sent.'], 422);
        }
        return response()->json(['message' => 'Sent.']);
    }

    /** The chat ids this contact's conversation could be under, most likely first. @return string[] */
    private function chatCandidates(Contact $c): array
    {
        $digits = preg_replace('/\D+/', '', (string) $c->phone);
        $ids = [];
        if ($c->wa_id) { $ids[] = $c->wa_id; }
        if ($digits !== '') {
            $ids = array_merge($ids, $c->phoneLooksReal() ? ["{$digits}@c.us", "{$digits}@lid"] : ["{$digits}@lid", "{$digits}@c.us"]);
        }
        foreach ((array) ($c->phone_aliases ?? []) as $a) {
            $ids[] = preg_replace('/\D+/', '', (string) $a) . '@lid';
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /** @return array{messages: array<int,array>, total: int}|null  null = the gateway could not be reached / refused */
    private function messages(string $token, WahaSession $session, string $chatId, int $limit): ?array
    {
        try {
            $res = Http::withHeaders(['X-API-Key' => $token])->timeout(8)
                ->get("{$this->base()}/api/sessions/{$session->session_name}/messages", ['chatId' => $chatId, 'limit' => $limit]);
        } catch (\Throwable) {
            return null;
        }
        if (!$res->successful()) {
            return null;
        }
        $rows = $res->json('messages') ?? [];
        $out = collect($rows)->map(function ($m) {
            $meta = is_array($m['metadata'] ?? null) ? $m['metadata'] : [];
            $media = $this->media($meta['media'] ?? null);
            // the gateway's type, tidied: older rows carry whatsapp-web.js tokens, and a media message with no type is recognised by its mimetype
            $type = match ($m['type'] ?? 'text') { 'chat' => 'text', 'ptt' => 'voice', 'vcard', 'multi_vcard' => 'contact', default => (string) ($m['type'] ?? 'text') };
            if (in_array($type, ['unknown', '', 'text'], true) && $media && !empty($media['mimetype'])) {
                $mt = (string) $media['mimetype'];
                $type = str_starts_with($mt, 'image/') ? 'image' : (str_starts_with($mt, 'video/') ? 'video' : (str_starts_with($mt, 'audio/') ? 'audio' : 'document'));
            }
            $content = ['body' => (string) ($m['body'] ?? '')];
            if ($media) { $content['media'] = $media; }
            if (is_array($meta['location'] ?? null)) { $content['location'] = $meta['location']; }

            return [
                'id'         => $m['id'] ?? $m['waMessageId'] ?? uniqid(),
                'direction'  => ($m['direction'] ?? '') === 'outgoing' ? 'outbound' : 'inbound',
                'type'       => $type,
                'content'    => $content,
                'created_at' => $m['createdAt'] ?? (isset($m['timestamp']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $m['timestamp'])->toIso8601String() : null),
                'origin'     => $m['origin'] ?? null,        // ai | manual | chatbot — for outgoing messages
                'sent_by'    => !empty($m['origin']) && $m['origin'] !== 'manual' ? ['name' => $m['origin'] === 'ai' ? 'AI agent' : 'Chatbot'] : null,
            ];
        })->filter(fn ($m) => $m['created_at'])->sortBy('created_at')->values()->all();

        return ['messages' => $out, 'total' => (int) ($res->json('total') ?? count($out))];
    }

    /**
     * A gateway message's media in the shape the apps draw: a ready-to-use url (a link, or the inline base64 as a data: url when it is small
     * enough), its mimetype and file name. Media too big to send along (or withheld by the gateway) is flagged `omitted` so the screen can say so.
     */
    private function media(mixed $media): ?array
    {
        if (!is_array($media)) { return null; }
        $mime = (string) ($media['mimetype'] ?? '');
        $name = $media['filename'] ?? null;
        $data = is_string($media['data'] ?? null) ? $media['data'] : '';
        if ($data !== '' && preg_match('#^https?://#i', $data)) {
            return ['url' => $data, 'mimetype' => $mime, 'filename' => $name];
        }
        if ($data !== '' && empty($media['omitted']) && strlen($data) <= 3_000_000) {   // ~2.2 MB of file; bigger ones are left out of a preview list
            return ['url' => 'data:' . ($mime ?: 'application/octet-stream') . ';base64,' . $data, 'mimetype' => $mime, 'filename' => $name];
        }

        return ['omitted' => true, 'mimetype' => $mime, 'filename' => $name];
    }

    /** The real number (digits) behind a privacy id, or null when the gateway cannot map it. */
    private function resolvePhone(string $token, WahaSession $session, string $chatId): ?string
    {
        try {
            $res = Http::withHeaders(['X-API-Key' => $token])->timeout(8)
                ->get("{$this->base()}/api/sessions/{$session->session_name}/contacts/" . rawurlencode($chatId) . '/phone');
        } catch (\Throwable) {
            return null;
        }
        $phone = $res->successful() ? preg_replace('/\D+/', '', (string) $res->json('phone')) : '';
        return strlen($phone) >= 7 && strlen($phone) <= 15 ? $phone : null;
    }
}
