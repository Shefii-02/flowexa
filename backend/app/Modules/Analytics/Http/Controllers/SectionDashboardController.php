<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One dashboard per section — Tasks, Meta Ads, Instagram, Messenger, AI Agent — each living under its own URL prefix
 * (tasks/dashboard, meta-ads/dashboard, instagram/dashboard?channel=…, wa-agent/dashboard) so the same plan-module gate and permission
 * that guard the rest of that section guard its dashboard too.
 *
 * Every dashboard answers the same shape so the web page is one component:
 *   { title, range_days, kpis:[{label,value,hint?,tone?}], series:{title, lines:[{label,color,points:[{date,value}]}]}, lists:[{title,columns,rows}] }
 */
class SectionDashboardController extends Controller
{
    private const DAYS = 30;

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    /** Daily counts for the last DAYS days, zero-filled: ['2026-11-01' => 3, …]. */
    private function daily($rows): array
    {
        $by = collect($rows)->mapWithKeys(fn ($r) => [(string) $r->d => (float) $r->v]);
        $out = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $out[] = ['date' => $d, 'value' => $by[$d] ?? 0];
        }

        return $out;
    }

    private function since(): string
    {
        return Carbon::today()->subDays(self::DAYS - 1)->startOfDay()->toDateTimeString();
    }

    private function respond(string $title, array $kpis, string $seriesTitle, array $lines, array $lists): JsonResponse
    {
        return response()->json([
            'title' => $title, 'range_days' => self::DAYS, 'kpis' => $kpis,
            'series' => ['title' => $seriesTitle, 'lines' => $lines], 'lists' => $lists,
        ]);
    }

    // ─────────────────────────── Tasks ───────────────────────────

    /** GET /tasks/dashboard — the company's tasks for a team head / admin, only your own for everyone else. */
    public function tasks(): JsonResponse
    {
        $u = auth()->user()->loadMissing('role');
        $cid = $this->companyId();
        $team = TeamMembersController::canSeeTeam($u);
        $base = fn () => DB::table('crm_tasks')->where('company_id', $cid)->when(!$team, fn ($q) => $q->where('assigned_to', $u->id));
        $now = now()->toDateTimeString();

        $open = $base()->where('status', 'open');
        $kpis = [
            ['label' => 'Open', 'value' => (clone $open)->count()],
            ['label' => 'Due today', 'value' => (clone $open)->whereDate('due_at', today())->count(), 'tone' => 'amber'],
            ['label' => 'Overdue', 'value' => (clone $open)->where('due_at', '<', $now)->count(), 'tone' => 'red'],
            ['label' => 'Done today', 'value' => $base()->where('status', 'done')->whereDate('completed_at', today())->count(), 'tone' => 'green'],
            ['label' => 'Done this week', 'value' => $base()->where('status', 'done')->where('completed_at', '>=', now()->startOfWeek()->toDateTimeString())->count(), 'tone' => 'green'],
            ['label' => 'Follow-ups open', 'value' => (clone $open)->where('type', 'followup')->count()],
        ];

        $done = $this->daily($base()->where('status', 'done')->where('completed_at', '>=', $this->since())
            ->selectRaw('DATE(completed_at) d, COUNT(*) v')->groupBy('d')->get());
        $created = $this->daily($base()->where('created_at', '>=', $this->since())
            ->selectRaw('DATE(created_at) d, COUNT(*) v')->groupBy('d')->get());

        $lists = [[
            'title' => 'By type', 'columns' => ['Type', 'Open', 'Overdue', 'Done'],
            'rows' => $base()->selectRaw("type, SUM(status='open') o, SUM(status='open' AND due_at < ?) ov, SUM(status='done') dn", [$now])
                ->groupBy('type')->orderByDesc('o')->get()->map(fn ($r) => [ucfirst((string) $r->type), (int) $r->o, (int) $r->ov, (int) $r->dn])->all(),
        ]];
        if ($team) {
            $lists[] = [
                'title' => 'By person', 'columns' => ['Person', 'Open', 'Overdue', 'Done this week'],
                'rows' => DB::table('crm_tasks as t')->join('users as u', 'u.id', '=', 't.assigned_to')->where('t.company_id', $cid)
                    ->selectRaw("u.name, SUM(t.status='open') o, SUM(t.status='open' AND t.due_at < ?) ov, SUM(t.status='done' AND t.completed_at >= ?) w", [$now, now()->startOfWeek()->toDateTimeString()])
                    ->groupBy('u.id', 'u.name')->orderByDesc('o')->limit(20)->get()
                    ->map(fn ($r) => [$r->name, (int) $r->o, (int) $r->ov, (int) $r->w])->all(),
            ];
        }

        return $this->respond($team ? 'Tasks · whole team' : 'Tasks · mine', $kpis, 'Tasks created vs completed',
            [['label' => 'Created', 'color' => '#6366f1', 'points' => $created], ['label' => 'Completed', 'color' => '#16a34a', 'points' => $done]], $lists);
    }

    // ─────────────────────────── Meta Ads ───────────────────────────

    /** GET /meta-ads/dashboard — spend, reach and leads over the last 30 days. */
    public function metaAds(): JsonResponse
    {
        $cid = $this->companyId();
        $ins = fn () => DB::table('meta_insights')->where('company_id', $cid)->where('object_type', 'campaign')->where('date', '>=', Carbon::today()->subDays(self::DAYS - 1)->toDateString());
        $t = $ins()->selectRaw('COALESCE(SUM(spend),0) spend, COALESCE(SUM(impressions),0) imp, COALESCE(SUM(clicks),0) clk, COALESCE(SUM(leads),0) leads')->first();
        $ctr = $t->imp > 0 ? round($t->clk / $t->imp * 100, 2) : 0;
        $cpl = $t->leads > 0 ? round($t->spend / $t->leads, 2) : 0;
        $camps = fn () => DB::table('meta_campaigns')->where('company_id', $cid)->whereNull('deleted_at');
        $leadRows = fn () => DB::table('meta_leads')->where('company_id', $cid)->where('created_at', '>=', $this->since());

        $kpis = [
            ['label' => 'Spend (30 d)', 'value' => round((float) $t->spend, 2), 'format' => 'money'],
            ['label' => 'Impressions', 'value' => (int) $t->imp],
            ['label' => 'Clicks', 'value' => (int) $t->clk],
            ['label' => 'CTR', 'value' => $ctr, 'format' => 'percent'],
            ['label' => 'Leads', 'value' => (int) $t->leads, 'tone' => 'green'],
            ['label' => 'Cost per lead', 'value' => $cpl, 'format' => 'money'],
            ['label' => 'Active campaigns', 'value' => (clone $camps())->where('status', 'ACTIVE')->count()],
            ['label' => 'Ad accounts', 'value' => DB::table('meta_ad_accounts')->where('company_id', $cid)->whereNull('deleted_at')->where('is_active', true)->count()],
            ['label' => 'Leads not processed', 'value' => $leadRows()->whereIn('process_status', ['failed', 'error'])->count(), 'tone' => 'red'],
        ];

        $spend = $this->daily($ins()->selectRaw('date d, SUM(spend) v')->groupBy('date')->get());
        $leads = $this->daily($ins()->selectRaw('date d, SUM(leads) v')->groupBy('date')->get());

        $top = $ins()->selectRaw('object_id, SUM(spend) s, SUM(leads) l, SUM(clicks) c')->groupBy('object_id')->orderByDesc('s')->limit(8)->get();
        $names = DB::table('meta_campaigns')->whereIn('id', $top->pluck('object_id'))->pluck('name', 'id');

        return $this->respond('Meta Ads · last 30 days', $kpis, 'Daily spend and leads',
            [['label' => 'Spend', 'color' => '#6366f1', 'points' => $spend], ['label' => 'Leads', 'color' => '#16a34a', 'points' => $leads]],
            [[
                'title' => 'Top campaigns by spend', 'columns' => ['Campaign', 'Spend', 'Clicks', 'Leads', 'Cost / lead'],
                'rows' => $top->map(fn ($r) => [$names[$r->object_id] ?? "Campaign #{$r->object_id}", round((float) $r->s, 2), (int) $r->c, (int) $r->l, $r->l > 0 ? round($r->s / $r->l, 2) : '—'])->all(),
            ]]);
    }

    // ─────────────────────────── Instagram / Messenger ───────────────────────────

    /** GET /instagram/dashboard?channel=instagram|facebook — Messenger is the same engine on channel = facebook. */
    public function social(Request $request): JsonResponse
    {
        $channel = $request->query('channel') === 'facebook' ? 'facebook' : 'instagram';
        $cid = $this->companyId();
        $accounts = fn () => DB::table('instagram_accounts')->where('company_id', $cid)->where('channel', $channel)->whereNull('deleted_at');
        $accIds = $accounts()->pluck('id');
        $convs = fn () => DB::table('instagram_conversations')->where('company_id', $cid)->whereIn('instagram_account_id', $accIds);
        $msgs = fn () => DB::table('instagram_messages as m')->join('instagram_conversations as c', 'c.id', '=', 'm.instagram_conversation_id')
            ->where('c.company_id', $cid)->whereIn('c.instagram_account_id', $accIds)->where('m.created_at', '>=', $this->since());
        $runs = fn () => DB::table('instagram_flow_runs')->where('company_id', $cid)->whereIn('instagram_account_id', $accIds)->where('created_at', '>=', $this->since());

        $kpis = [
            ['label' => $channel === 'facebook' ? 'Pages connected' : 'Accounts connected', 'value' => $accounts()->where('is_active', true)->count()],
            ['label' => 'Conversations', 'value' => $convs()->count()],
            ['label' => 'Unread', 'value' => (int) $convs()->sum('unread_count'), 'tone' => 'amber'],
            ['label' => 'Needs a person', 'value' => $convs()->where('needs_agent', true)->count(), 'tone' => 'red'],
            ['label' => 'Messages received (30 d)', 'value' => $msgs()->where('m.direction', 'in')->count()],
            ['label' => 'Messages sent (30 d)', 'value' => $msgs()->where('m.direction', 'out')->count()],
            ['label' => 'Leads captured', 'value' => $convs()->whereNotNull('lead_id')->count(), 'tone' => 'green'],
            ['label' => 'Flow runs (30 d)', 'value' => $runs()->count()],
            ['label' => 'Flow runs failed', 'value' => $runs()->where('status', 'failed')->count(), 'tone' => 'red'],
        ];
        if ($channel === 'instagram') {
            $kpis[] = ['label' => 'Comment triggers (30 d)', 'value' => DB::table('instagram_comment_events')->where('company_id', $cid)->whereIn('instagram_account_id', $accIds)->where('created_at', '>=', $this->since())->count()];
        }

        $in = $this->daily($msgs()->where('m.direction', 'in')->selectRaw('DATE(m.created_at) d, COUNT(*) v')->groupBy('d')->get());
        $out = $this->daily($msgs()->where('m.direction', 'out')->selectRaw('DATE(m.created_at) d, COUNT(*) v')->groupBy('d')->get());

        $convCount = DB::table('instagram_conversations')->where('company_id', $cid)->whereIn('instagram_account_id', $accIds)
            ->selectRaw('instagram_account_id a, COUNT(*) n')->groupBy('a')->pluck('n', 'a');

        return $this->respond(($channel === 'facebook' ? 'Messenger' : 'Instagram') . ' · last 30 days', $kpis, 'Messages per day',
            [['label' => 'Received', 'color' => '#6366f1', 'points' => $in], ['label' => 'Sent', 'color' => '#16a34a', 'points' => $out]],
            [
                [
                    'title' => $channel === 'facebook' ? 'Pages' : 'Accounts', 'columns' => ['Name', 'Status', 'Conversations'] + ($channel === 'instagram' ? [3 => 'Followers'] : []),
                    'rows' => $accounts()->orderBy('name')->get()->map(fn ($a) => array_values(array_filter([
                        $a->username ? '@' . $a->username : ($a->page_name ?: $a->name), $a->is_active ? ucfirst((string) $a->status) : 'Disconnected', (int) ($convCount[$a->id] ?? 0),
                        $channel === 'instagram' ? (int) $a->followers_count : null,
                    ], fn ($v) => $v !== null)))->all(),
                ],
                [
                    'title' => 'Top flows', 'columns' => ['Flow', 'Triggered', 'DMs sent', 'Leads'],
                    'rows' => DB::table('instagram_flows')->where('company_id', $cid)->whereIn('instagram_account_id', $accIds)->whereNull('deleted_at')
                        ->orderByDesc('triggered_count')->limit(8)->get()->map(fn ($f) => [$f->name, (int) $f->triggered_count, (int) $f->dms_sent, (int) $f->leads])->all(),
                ],
            ]);
    }

    // ─────────────────────────── AI Agent ───────────────────────────

    /** GET /wa-agent/dashboard — agents, conversations they handle and what they produce. */
    public function aiAgent(): JsonResponse
    {
        $cid = $this->companyId();
        $sess = fn () => DB::table('ai_agent_sessions')->where('company_id', $cid);
        $kpis = [
            ['label' => 'Active agents', 'value' => DB::table('agent_playbooks')->where('company_id', $cid)->where('is_active', true)->count()],
            ['label' => 'Conversations', 'value' => $sess()->count()],
            ['label' => 'Active (7 d)', 'value' => $sess()->where('last_message_at', '>=', now()->subDays(7)->toDateTimeString())->count()],
            ['label' => 'New (30 d)', 'value' => $sess()->where('created_at', '>=', $this->since())->count()],
            ['label' => 'Qualified', 'value' => $sess()->where('qualification_status', 'complete')->count(), 'tone' => 'green'],
            ['label' => 'Handed to a person', 'value' => $sess()->where('qualification_status', 'escalated')->count(), 'tone' => 'amber'],
            ['label' => 'Knowledge documents', 'value' => DB::table('ai_knowledge_base')->where('company_id', $cid)->count()],
            ['label' => 'Pipeline runs (30 d)', 'value' => DB::table('ai_pipeline_runs')->where('company_id', $cid)->where('created_at', '>=', $this->since())->count()],
            ['label' => 'Pipeline runs failed', 'value' => DB::table('ai_pipeline_runs')->where('company_id', $cid)->where('created_at', '>=', $this->since())->where('status', 'failed')->count(), 'tone' => 'red'],
        ];

        $started = $this->daily($sess()->where('created_at', '>=', $this->since())->selectRaw('DATE(created_at) d, COUNT(*) v')->groupBy('d')->get());
        $done = $this->daily($sess()->where('qualification_status', 'complete')->where('updated_at', '>=', $this->since())->selectRaw('DATE(updated_at) d, COUNT(*) v')->groupBy('d')->get());

        $byStatus = $sess()->selectRaw('qualification_status s, COUNT(*) n')->groupBy('s')->orderByDesc('n')->get();

        return $this->respond('AI Agent · last 30 days', $kpis, 'New conversations vs qualified',
            [['label' => 'New', 'color' => '#6366f1', 'points' => $started], ['label' => 'Qualified', 'color' => '#16a34a', 'points' => $done]],
            [
                ['title' => 'Conversations by outcome', 'columns' => ['Outcome', 'Conversations'],
                    'rows' => $byStatus->map(fn ($r) => [ucfirst(str_replace('_', ' ', (string) $r->s)), (int) $r->n])->all()],
                ['title' => 'Agents', 'columns' => ['Agent', 'Function', 'Status'],
                    'rows' => DB::table('agent_playbooks')->where('company_id', $cid)->orderByDesc('is_active')->limit(12)->get()
                        ->map(fn ($p) => [$p->agent_name ?: 'Agent', ucfirst(str_replace('_', ' ', (string) $p->function_key)), $p->is_active ? 'Active' : 'Off'])->all()],
            ]);
    }
}
