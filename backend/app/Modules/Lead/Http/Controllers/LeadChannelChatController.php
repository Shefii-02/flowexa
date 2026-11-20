<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Modules\Lead\Services\LeadService;

/**
 * Every conversation a lead's contact has had, by channel — so the lead page can show the right thread:
 *   wa_cloud  → wa_conversations   (read through the existing conversations API)
 *   wa_chat   → live from the chat gateway (GET /leads/{lead}/wa-chat)
 *   instagram → instagram_conversations / instagram_messages
 *   website   → widget_conversations / widget_messages
 * Facebook Messenger is not connected to any chat storage in the system, so there is nothing to show for it — said plainly.
 */
class LeadChannelChatController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    /** GET /leads/{lead}/chats[?channel=instagram|website][&conversation=ID] */
    public function show(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate(['channel' => ['nullable', 'in:instagram,website'], 'conversation' => ['nullable', 'integer']]);
        $u = auth()->user();
        $l = $this->leads->show($lead, $u->company_id, $u->id, $u->hasPermission('leads.view_all'));
        $cid = (int) $u->company_id;
        $contactId = (int) $l->contact_id;

        $ig = $this->instagramThreads($cid, $contactId, (int) $l->id);
        $web = $this->widgetThreads($cid, $contactId, (int) $l->id);
        $waCloud = Schema::hasTable('wa_conversations') ? DB::table('wa_conversations')->where('company_id', $cid)->where('contact_id', $contactId)->count() : 0;

        $out = [
            'channels' => [
                'wa_cloud'  => ['available' => $waCloud > 0, 'threads' => $waCloud],
                'wa_chat'   => ['available' => $l->contact?->wa_id !== null || $l->source === 'wa_chat' || $l->origin_type === 'wa_session', 'threads' => null],
                'instagram' => ['available' => count($ig) > 0, 'threads' => count($ig), 'list' => $ig],
                'website'   => ['available' => count($web) > 0, 'threads' => count($web), 'list' => $web],
                'facebook'  => ['available' => false, 'threads' => 0, 'note' => 'Facebook Messenger chats are not stored in Teamzo, so there is no conversation to show here. Facebook leads arrive through Meta lead forms.'],
            ],
            // which tab to open first: the channel the lead came in on
            'suggested' => match (true) {
                $l->source === 'wa_chat' || $l->origin_type === 'wa_session' => 'wa_chat',
                $l->source === 'whatsapp_cloud' => 'wa_cloud',
                in_array($l->source, ['instagram', 'instagram_dm'], true) => 'instagram',
                in_array($l->source, ['website', 'website_widget', 'widget'], true) => 'website',
                default => null,
            },
        ];

        if (!empty($d['channel'])) {
            $list = $d['channel'] === 'instagram' ? $ig : $web;
            $ids = collect($list)->pluck('id');
            $pick = !empty($d['conversation']) ? (int) $d['conversation'] : ($list[0]['id'] ?? null);
            abort_if($pick && !$ids->contains($pick), 404, 'Conversation not found.');
            $out['messages'] = $pick ? ($d['channel'] === 'instagram' ? $this->instagramMessages($pick) : $this->widgetMessages($pick)) : [];
            $out['conversation'] = $pick;
        }
        return response()->json($out);
    }

    /** This contact's Instagram DMs (a lead can also be linked to the conversation directly) — newest first. */
    private function instagramThreads(int $cid, int $contactId, int $leadId): array
    {
        if (!Schema::hasTable('instagram_conversations')) { return []; }
        return DB::table('instagram_conversations')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('contact_id', $contactId)->orWhere('lead_id', $leadId))
            ->orderByDesc('last_message_at')->limit(10)
            ->get(['id', 'participant_username', 'last_message_preview', 'last_message_at', 'status'])
            ->map(fn ($r) => ['id' => $r->id, 'label' => $r->participant_username ? '@' . ltrim($r->participant_username, '@') : 'Instagram chat', 'last' => $r->last_message_preview, 'last_at' => $r->last_message_at])->all();
    }

    private function widgetThreads(int $cid, int $contactId, int $leadId): array
    {
        if (!Schema::hasTable('widget_conversations')) { return []; }
        return DB::table('widget_conversations')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('contact_id', $contactId)->orWhere('lead_id', $leadId))
            ->orderByDesc('last_message_at')->limit(10)
            ->get(['id', 'visitor_name', 'page_url', 'last_message_at', 'status'])
            ->map(fn ($r) => ['id' => $r->id, 'label' => $r->visitor_name ?: 'Website visitor', 'last' => $r->page_url, 'last_at' => $r->last_message_at])->all();
    }

    /** Normalised to { id, from: 'them'|'us', text, at, via?, kind? } — the shape the chat card already draws. */
    private function instagramMessages(int $conv): array
    {
        return DB::table('instagram_messages')->where('instagram_conversation_id', $conv)->orderByDesc('id')->limit(200)->get()->reverse()->values()
            ->map(fn ($m) => ['id' => $m->id, 'from' => $m->direction === 'in' ? 'them' : 'us', 'text' => (string) $m->text,
                'at' => $m->sent_at ?: $m->created_at, 'via' => $m->direction === 'out' && $m->source ? $m->source : null, 'failed' => $m->status === 'failed'])->all();
    }

    private function widgetMessages(int $conv): array
    {
        return DB::table('widget_messages')->where('widget_conversation_id', $conv)->orderByDesc('id')->limit(200)->get()->reverse()->values()
            ->map(fn ($m) => ['id' => $m->id, 'from' => $m->role === 'visitor' ? 'them' : 'us', 'text' => (string) ($m->text ?: ($m->attachment_name ? '📎 ' . $m->attachment_name : '')),
                'at' => $m->created_at, 'via' => $m->role === 'visitor' ? null : $m->role])->all();
    }
}
