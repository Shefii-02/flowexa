<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ConversationTopic;
use App\Models\Wallet;
use App\Modules\Analytics\Http\Controllers\TeamMembersController;
use App\Modules\WaChat\Services\WaChatBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /v1/wa-chat/dashboard — the extra blocks on the WA Chat dashboard, all from Laravel's own tables (the gateway's live stats and the
 * charts already have their own endpoints): conversation KPIs, wallet & billing, API usage, campaigns, recent activity and a per-staff team
 * block. A company admin / team head sees the whole company; everyone else sees only their own campaigns and leads in the team block.
 */
class WaChatDashboardController extends Controller
{
    public function index(WaChatBilling $billing): JsonResponse
    {
        $u = auth()->user()->loadMissing('role');
        $company = $u->company;
        $cid = (int) $u->company_id;
        $team = TeamMembersController::canSeeTeam($u);
        $since30 = Carbon::today()->subDays(29)->startOfDay()->toDateTimeString();
        $today = Carbon::today()->toDateTimeString();
        $month = now()->startOfMonth()->toDateTimeString();

        // ── Conversation KPIs (1:1 messages the webhook logged for the wa_chat channel) ──
        $msgs = fn () => DB::table('message_logs')->where('company_id', $cid)->where('channel', 'wa_chat');
        $dayCount = fn (string $dir, string $from) => $msgs()->where('direction', $dir)->where('created_at', '>=', $from)->count();
        $outToday = $msgs()->where('direction', 'outbound')->where('created_at', '>=', $today);
        $outSent = (clone $outToday)->count();
        $conversation = [
            'received_today' => $dayCount('inbound', $today),
            'sent_today' => $dayCount('outbound', $today),
            'received_30d' => $dayCount('inbound', $since30),
            'sent_30d' => $dayCount('outbound', $since30),
            'contacts_today' => $msgs()->where('created_at', '>=', $today)->distinct()->count('phone'),
            'delivered_today' => (clone $outToday)->whereNotNull('delivered_at')->count(),
            'read_today' => (clone $outToday)->whereNotNull('read_at')->count(),
            'failed_today' => (clone $outToday)->where('status', 'failed')->count(),
            'delivery_rate' => $outSent > 0 ? round((clone $outToday)->whereNotNull('delivered_at')->count() / $outSent * 100, 1) : null,
            'avg_delivery_seconds' => (int) round((float) (clone $outToday)->whereNotNull('delivered_at')->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, delivered_at)) s')->value('s')),
            'topics' => collect(ConversationTopic::TOPICS)->map(fn ($label, $k) => [
                'topic' => $k, 'label' => $label,
                'count' => DB::table('conversation_topics')->where('company_id', $cid)->where('topic', $k)->count(),
            ])->values(),
        ];

        // ── Wallet & billing ──
        $wallet = Wallet::where('company_id', $cid)->first();
        $charges = fn () => DB::table('wa_chat_charges')->where('company_id', $cid);
        $walletBlock = [
            'balance' => (float) ($wallet->balance ?? 0),
            'billing_mode' => $company->wa_chat_billing ?? 'wallet',
            'credits_per_message' => $billing->creditsPerMessage($company),
            'charged_today' => (int) $charges()->where('status', 'charged')->where('updated_at', '>=', $today)->sum('credits'),
            'charged_month' => (int) $charges()->where('status', 'charged')->where('updated_at', '>=', $month)->sum('credits'),
            'messages_billed_month' => $charges()->where('status', 'charged')->where('updated_at', '>=', $month)->count(),
            'pending' => $charges()->where('status', 'pending')->count(),
            'unbilled' => $charges()->where('status', 'unbilled')->count(),
            'by_source' => $charges()->where('status', 'charged')->where('updated_at', '>=', $month)->selectRaw('source, COUNT(*) n, SUM(credits) c')->groupBy('source')->get()
                ->map(fn ($r) => ['source' => $r->source, 'messages' => (int) $r->n, 'credits' => (int) $r->c])->all(),
        ];

        // ── API usage (OTP / utility sends and billed API messages) ──
        $otp = fn () => DB::table('openwa_otp_logs')->where('company_id', $cid);
        $api = [
            'services' => DB::table('openwa_otp_services')->where('company_id', $cid)->count(),
            'requests_today' => $otp()->where('created_at', '>=', $today)->count(),
            'requests_30d' => $otp()->where('created_at', '>=', $since30)->count(),
            'sent_30d' => $otp()->where('created_at', '>=', $since30)->whereIn('action', ['sent', 'resend'])->count(),
            'verified_30d' => $otp()->where('created_at', '>=', $since30)->where('action', 'verified')->count(),
            'failed_30d' => $otp()->where('created_at', '>=', $since30)->where('action', 'failed')->count(),
            'avg_response_ms' => (int) round((float) $otp()->where('created_at', '>=', $since30)->avg('response_ms')),
            'billed_messages_30d' => $charges()->where('source', 'api')->where('created_at', '>=', $since30)->count(),
            'daily' => $this->daily($otp()->where('created_at', '>=', $since30)->selectRaw('DATE(created_at) d, COUNT(*) v')->groupBy('d')->get()),
        ];

        // ── Campaigns ──
        $jobs = fn () => DB::table('openwa_message_sender_jobs')->where('company_id', $cid);
        $campaigns = [
            'running' => $jobs()->where('status', 'running')->count(),
            'scheduled' => $jobs()->where('status', 'scheduled')->count(),
            'done_30d' => $jobs()->where('status', 'done')->where('completed_at', '>=', $since30)->count(),
            'messages_sent_30d' => (int) $jobs()->where('created_at', '>=', $since30)->sum('sent'),
            'messages_failed_30d' => (int) $jobs()->where('created_at', '>=', $since30)->sum('failed'),
            'recent' => $jobs()->orderByDesc('id')->limit(6)->get(['id', 'campaign_name', 'status', 'total', 'sent', 'failed', 'created_at'])
                ->map(fn ($j) => ['id' => $j->id, 'name' => $j->campaign_name ?: "Campaign #{$j->id}", 'status' => $j->status,
                    'total' => (int) $j->total, 'sent' => (int) $j->sent, 'failed' => (int) $j->failed, 'at' => $j->created_at])->all(),
        ];

        // ── Recent activity: failures, sessions that need attention, latest campaign movement ──
        $activity = collect();
        DB::table('openwa_message_logs')->where('company_id', $cid)->where('status', 'failed')->orderByDesc('id')->limit(6)
            ->get(['recipient_phone', 'campaign_name', 'error_message', 'updated_at'])
            ->each(fn ($r) => $activity->push(['kind' => 'failed_send', 'text' => 'Send to ' . $r->recipient_phone . ' failed' . ($r->error_message ? ': ' . mb_strimwidth($r->error_message, 0, 80, '…') : ''), 'at' => $r->updated_at]));
        DB::table('openwa_sessions')->where('company_id', $cid)->whereNotIn('status', ['ready', 'READY'])->orderByDesc('updated_at')->limit(5)
            ->get(['display_name', 'session_name', 'status', 'updated_at'])
            ->each(fn ($s) => $activity->push(['kind' => 'session', 'text' => ($s->display_name ?: $s->session_name) . ' is ' . str_replace('_', ' ', (string) $s->status), 'at' => $s->updated_at]));
        DB::table('openwa_message_sender_jobs')->where('company_id', $cid)->whereIn('status', ['done', 'stopped', 'paused'])->orderByDesc('updated_at')->limit(5)
            ->get(['campaign_name', 'id', 'status', 'sent', 'total', 'updated_at'])
            ->each(fn ($j) => $activity->push(['kind' => 'campaign', 'text' => ($j->campaign_name ?: "Campaign #{$j->id}") . " {$j->status} ({$j->sent}/{$j->total} sent)", 'at' => $j->updated_at]));
        DB::table('wa_chat_charges')->where('company_id', $cid)->where('status', 'unbilled')->orderByDesc('id')->limit(3)->get(['phone', 'updated_at'])
            ->each(fn ($c) => $activity->push(['kind' => 'billing', 'text' => 'Delivered to ' . $c->phone . ' but the wallet was empty — not billed', 'at' => $c->updated_at]));
        $activity = $activity->sortByDesc('at')->take(12)->values();

        return response()->json([
            'conversation' => $conversation, 'wallet' => $walletBlock, 'api' => $api, 'campaigns' => $campaigns,
            'activity' => $activity, 'team' => $this->team($cid, $u, $team, $since30),
        ]);
    }

    /** Per person: campaigns started, messages those sent, WA Chat leads assigned and enrolled. Others only see their own row. */
    private function team(int $cid, $u, bool $all, string $since30): array
    {
        $people = DB::table('users')->where('company_id', $cid)->where('is_active', true)->when(!$all, fn ($q) => $q->where('id', $u->id))->orderBy('name')->get(['id', 'name']);
        $camp = DB::table('openwa_message_sender_jobs')->where('company_id', $cid)->where('created_at', '>=', $since30)->whereNotNull('created_by')
            ->selectRaw('created_by u, COUNT(*) n, COALESCE(SUM(sent),0) s')->groupBy('u')->get()->keyBy('u');
        $leads = DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at')->where('source', 'wa_chat')->whereNotNull('assigned_to')
            ->selectRaw("assigned_to u, COUNT(*) n, SUM(stage='enrolled') e")->groupBy('u')->get()->keyBy('u');

        return $people->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name,
            'campaigns' => (int) ($camp[$p->id]->n ?? 0), 'campaign_messages' => (int) ($camp[$p->id]->s ?? 0),
            'leads' => (int) ($leads[$p->id]->n ?? 0), 'enrolled' => (int) ($leads[$p->id]->e ?? 0),
        ])->sortByDesc(fn ($r) => $r['campaign_messages'] + $r['leads'])->values()->all();
    }

    private function daily($rows): array
    {
        $by = collect($rows)->mapWithKeys(fn ($r) => [(string) $r->d => (int) $r->v]);
        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $out[] = ['date' => $d, 'value' => $by[$d] ?? 0];
        }

        return $out;
    }
}
