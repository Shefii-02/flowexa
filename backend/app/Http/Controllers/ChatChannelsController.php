<?php

namespace App\Http\Controllers;

use App\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "My chats" per channel, for the round icons on a sales member's home. Only conversations assigned to the caller:
 *   wa_cloud  → wa_conversations.assigned_to, one source per WhatsApp number
 *   wa_chat   → leads assigned to them that came in on a WhatsApp session, one source per session
 *   instagram / messenger → conversations whose captured lead is theirs, one source per account / page
 *   widget    → website live-chat conversations whose captured lead is theirs, one source per widget
 */
class ChatChannelsController extends Controller
{
    private function safe(callable $f, $default)
    {
        try { return $f(); } catch (\Throwable) { return $default; }
    }

    /** GET /chat-channels/mine → {channels: [{key, label, total, sources: [{id, label, count}]}]} */
    public function mine(): JsonResponse
    {
        $u = auth()->user();
        $cid = (int) $u->company_id;

        $waCloud = $this->safe(function () use ($cid, $u) {
            $rows = DB::table('wa_conversations as c')->leftJoin('wa_phone_numbers as p', 'p.id', '=', 'c.wa_phone_number_id')
                ->where('c.company_id', $cid)->where('c.assigned_to', $u->id)
                ->groupBy('c.wa_phone_number_id', 'p.label', 'p.display_number')
                ->get(['c.wa_phone_number_id as id', 'p.label', 'p.display_number', DB::raw('count(*) as n'), DB::raw('sum(c.unread_count > 0) as unread')]);
            return $rows->map(fn ($r) => ['id' => $r->id, 'label' => $r->label ?: ($r->display_number ?: 'WhatsApp number'), 'count' => (int) $r->n, 'unread' => (int) $r->unread])->all();
        }, []);

        $waChat = $this->safe(function () use ($cid, $u) {
            $rows = DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at')->where('assigned_to', $u->id)
                ->where(fn ($q) => $q->where('origin_type', 'wa_session')->orWhere('source', 'wa_chat'))
                ->whereNotIn('stage', ['enrolled', 'lost'])
                ->groupBy('origin_id')->get(['origin_id as id', DB::raw('count(*) as n')]);
            $names = WahaSession::where('company_id', $cid)->pluck('name', 'id');
            return $rows->map(fn ($r) => ['id' => $r->id ? (int) $r->id : 0, 'label' => $r->id ? ($names[$r->id] ?? "Session #{$r->id}") : 'Other WhatsApp chats', 'count' => (int) $r->n])->all();
        }, []);

        $social = fn (string $channel) => $this->safe(function () use ($cid, $u, $channel) {
            $rows = DB::table('instagram_conversations as c')->join('instagram_accounts as a', 'a.id', '=', 'c.instagram_account_id')
                ->join('leads as l', 'l.id', '=', 'c.lead_id')
                ->where('c.company_id', $cid)->where('a.channel', $channel)->where('l.assigned_to', $u->id)->whereNull('l.deleted_at')
                ->groupBy('c.instagram_account_id', 'a.username')
                ->get(['c.instagram_account_id as id', 'a.username', DB::raw('count(*) as n'), DB::raw('sum(c.unread_count > 0) as unread')]);
            return $rows->map(fn ($r) => ['id' => $r->id, 'label' => $r->username ?: 'Account', 'count' => (int) $r->n, 'unread' => (int) $r->unread])->all();
        }, []);

        $widget = $this->safe(function () use ($cid, $u) {
            $rows = DB::table('widget_conversations as c')->join('chat_widgets as w', 'w.id', '=', 'c.chat_widget_id')
                ->join('leads as l', 'l.id', '=', 'c.lead_id')
                ->where('w.company_id', $cid)->where('l.assigned_to', $u->id)->whereNull('l.deleted_at')
                ->groupBy('c.chat_widget_id', 'w.name')
                ->get(['c.chat_widget_id as id', 'w.name', DB::raw('count(*) as n')]);
            return $rows->map(fn ($r) => ['id' => $r->id, 'label' => $r->name ?: 'Website widget', 'count' => (int) $r->n])->all();
        }, []);

        $pack = fn (string $key, string $label, array $sources) => ['key' => $key, 'label' => $label, 'total' => array_sum(array_column($sources, 'count')), 'sources' => $sources];

        return response()->json(['channels' => [
            $pack('wa_cloud', 'WA Cloud', $waCloud),
            $pack('wa_chat', 'WA Chat', $waChat),
            $pack('instagram', 'Instagram', $social('instagram')),
            $pack('messenger', 'Messenger', $social('facebook')),
            $pack('widget', 'Web widget', $widget),
        ]]);
    }

    /** GET /chat-channels/wa-chat?session= — my leads that came in on WhatsApp (a session), newest activity first. Opening one shows its WhatsApp chat. */
    public function waChat(Request $request): JsonResponse
    {
        $u = auth()->user();
        $rows = DB::table('leads as l')->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('l.company_id', $u->company_id)->whereNull('l.deleted_at')->where('l.assigned_to', $u->id)
            ->where(fn ($q) => $q->where('l.origin_type', 'wa_session')->orWhere('l.source', 'wa_chat'))
            ->whereNotIn('l.stage', ['enrolled', 'lost'])
            ->when($request->filled('session'), fn ($q) => (int) $request->query('session') > 0 ? $q->where('l.origin_id', (int) $request->query('session')) : $q->whereNull('l.origin_id'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('c.name', 'like', $t)->orWhere('c.phone', 'like', $t));
            })
            ->orderByDesc('l.updated_at')->limit(200)
            ->get(['l.id', 'l.stage', 'l.updated_at', 'c.name', 'c.phone']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?: $r->phone, 'phone' => $r->phone, 'stage' => $r->stage, 'updated_at' => $r->updated_at])->values()]);
    }
}
