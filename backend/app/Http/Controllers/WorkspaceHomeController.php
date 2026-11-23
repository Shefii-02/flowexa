<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Modules\Analytics\Http\Controllers\TeamMembersController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /home/{workspace} — everything one mobile "workspace" Home shows, in one call, so the app never has to stitch ten endpoints together
 * (or show an empty block when one of them is not allowed for the user). Workspaces: sales, hr, administrators, marketing, accounts.
 *
 * Sections the user may not see, or tables a company does not use, come back as empty lists / nulls — never as an error — so a screen always renders.
 * A team head (owner / admin / team lead …) sees the whole company; everyone else sees their own numbers where that makes sense.
 */
class WorkspaceHomeController extends Controller
{
    private const PERMISSIONS = [
        'sales'          => ['leads.view', 'contacts.view', 'wa_agent.leads.view'],
        'hr'             => ['hr.manage', 'hr.attendance.view_all', 'hr.leave.approve', 'staff.view'],
        'administrators' => ['staff.view', 'staff.manage', 'settings.manage'],
        'marketing'      => ['meta_ads.view', 'instagram.view', 'templates.view', 'campaigns.view', 'wa_chat.message_sender.view'],
        'accounts'       => ['accounts.read', 'accounts.manage', 'billing.view'],
    ];

    private function has(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable) { return false; }
    }

    private function cid(): int { return (int) auth()->user()->company_id; }

    /** Run one section; a failure (missing table, odd data) leaves that section empty instead of breaking the whole screen. */
    private function safe(callable $f, mixed $fallback = null): mixed
    {
        try { return $f(); } catch (\Throwable $e) { report($e); return $fallback; }
    }

    public function show(string $workspace): JsonResponse
    {
        abort_unless(isset(self::PERMISSIONS[$workspace]), 404);
        $u = auth()->user()->loadMissing('role');
        abort_unless($u->isSuperAdmin() || $u->isOwner() || TeamMembersController::canSeeTeam($u) || $u->hasAnyPermission(self::PERMISSIONS[$workspace]), 403, 'This workspace is not available for your account.');

        return response()->json(['workspace' => $workspace, 'generated_at' => now()->toIso8601String()] + $this->{$workspace}($u, TeamMembersController::canSeeTeam($u)));
    }

    // ───────────────────────────── Sales ─────────────────────────────
    private function sales($u, bool $team): array
    {
        $cid = $this->cid();
        $today = Carbon::today();
        $leads = fn () => DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at')->when(!$team, fn ($q) => $q->where('assigned_to', $u->id));
        $tasks = fn () => DB::table('crm_tasks')->where('company_id', $cid)->when(!$team, fn ($q) => $q->where('assigned_to', $u->id));
        $now = now()->toDateTimeString();

        $dueToday = $this->safe(fn () => $tasks()->where('status', 'open')->whereDate('due_at', $today)->count(), 0);
        $overdue = $this->safe(fn () => $tasks()->where('status', 'open')->where('due_at', '<', $today->toDateTimeString())->count(), 0);
        $doneToday = $this->safe(fn () => $tasks()->where('status', 'done')->whereDate('completed_at', $today)->count(), 0);

        $stageCounts = $this->safe(fn () => $leads()->selectRaw('stage, COUNT(*) n')->groupBy('stage')->pluck('n', 'stage')->all(), []);
        $stages = [];
        foreach (['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'enrolled' => 'Won', 'lost' => 'Lost'] as $k => $label) {
            $stages[] = ['key' => $k, 'label' => $label, 'count' => (int) ($stageCounts[$k] ?? 0)];
        }

        $contacts = fn () => DB::table('contacts')->where('company_id', $cid)->whereNull('deleted_at');

        return [
            'followups' => ['done' => $doneToday, 'due_today' => $dueToday, 'overdue' => $overdue, 'total_today' => $doneToday + $dueToday],
            'kpis' => [
                'leads'    => ['total' => $this->safe(fn () => $leads()->count(), 0), 'new_today' => $this->safe(fn () => $leads()->where('created_at', '>=', $today->toDateTimeString())->count(), 0)],
                'contacts' => ['total' => $this->safe(fn () => $contacts()->count(), 0), 'new_week' => $this->safe(fn () => $contacts()->where('created_at', '>=', now()->subDays(7)->toDateTimeString())->count(), 0)],
                'chats'    => ['unread' => $this->safe(fn () => $this->unreadChats($cid), 0)],
                'deals'    => $this->safe(fn () => ['open' => DB::table('crm_deals')->where('company_id', $cid)->where('status', 'open')->count(),
                    'value' => (float) DB::table('crm_deals')->where('company_id', $cid)->where('status', 'open')->sum('value')], ['open' => 0, 'value' => 0]),
            ],
            'recent_chats' => $this->safe(fn () => $this->recentChats($cid), []),
            'followups_due' => $this->safe(fn () => $tasks()->where('status', 'open')->orderBy('due_at')->limit(4)->get(['id', 'title', 'type', 'due_at', 'contact_id'])
                ->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'type' => $t->type, 'due_at' => $t->due_at, 'overdue' => $t->due_at && $t->due_at < $now,
                    'contact' => $t->contact_id ? DB::table('contacts')->where('id', $t->contact_id)->value('name') : null])->all(), []),
            'leads_by_stage' => $stages,
            // the leads people reached out about most recently — the "Hot leads" list on a sales member's own home
            'hot_leads' => $this->safe(fn () => DB::table('leads')->leftJoin('contacts as c', 'c.id', '=', 'leads.contact_id')->where('leads.company_id', $cid)->whereNull('leads.deleted_at')
                ->when(!$team, fn ($q) => $q->where('leads.assigned_to', $u->id))->whereNotIn('leads.stage', ['enrolled', 'lost'])
                ->orderByDesc('leads.updated_at')->limit(3)->get(['leads.id', 'leads.stage', 'leads.updated_at', 'c.name', 'c.phone'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name ?: $l->phone, 'phone' => $l->phone, 'stage' => $l->stage, 'updated_at' => $l->updated_at])->all(), []),
        ];
    }

    private function unreadChats(int $cid): int
    {
        // People who messaged on WhatsApp in the last day.
        return (int) DB::table('message_logs')->where('company_id', $cid)->where('direction', 'inbound')->where('created_at', '>=', now()->subDay()->toDateTimeString())->distinct()->count('phone');
    }

    private function recentChats(int $cid): array
    {
        $rows = DB::table('message_logs')->where('company_id', $cid)->where('direction', 'inbound')->orderByDesc('id')->limit(60)->get(['phone', 'channel', 'content', 'type', 'created_at']);
        $seen = []; $out = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->phone])) { continue; }
            $seen[$r->phone] = true;
            $c = json_decode((string) $r->content, true);
            $text = is_array($c) ? ($c['body'] ?? $c['text'] ?? $c['caption'] ?? ($c['text']['body'] ?? null)) : (is_string($c) ? $c : null);
            $out[] = ['phone' => $r->phone, 'name' => DB::table('contacts')->where('company_id', $cid)->where('phone', $r->phone)->value('name') ?: $r->phone,
                'channel' => in_array($r->channel, ['wa_chat', 'wa_cloud'], true) ? 'WhatsApp' : ucfirst((string) $r->channel),
                'preview' => is_string($text) ? mb_strimwidth($text, 0, 70, '…') : ucfirst((string) $r->type), 'at' => $r->created_at];
            if (count($out) >= 4) { break; }
        }

        return $out;
    }

    // ───────────────────────────── HR ─────────────────────────────
    private function hr($u, bool $team): array
    {
        $cid = $this->cid();
        $today = Carbon::today()->toDateString();
        $att = fn () => DB::table('hr_attendance')->where('company_id', $cid)->when(!$team, fn ($q) => $q->where('user_id', $u->id));
        $staff = $team ? DB::table('users')->where('company_id', $cid)->where('is_active', true)->count() : 1;
        $in = $this->safe(fn () => $att()->whereDate('work_date', $today)->whereNotNull('clock_in_at')->count(), 0);
        $late = $this->safe(fn () => $att()->whereDate('work_date', $today)->whereNotNull('clock_in_at')->where('late_minutes', '>', 0)->count(), 0);
        $onLeave = $this->safe(fn () => $att()->whereDate('work_date', $today)->where('status', 'on_leave')->count(), 0);
        $month = now()->startOfMonth()->toDateString();
        $daysSoFar = max(1, Carbon::parse($month)->diffInDays(Carbon::today()) + 1);
        $monthPresent = $this->safe(fn () => $att()->where('work_date', '>=', $month)->whereNotNull('clock_in_at')->count(), 0);

        $leave = fn () => DB::table('hr_leave_requests as l')->join('users as u', 'u.id', '=', 'l.user_id')->leftJoin('hr_leave_types as t', 't.id', '=', 'l.leave_type_id')
            ->where('l.company_id', $cid)->when(!$team, fn ($q) => $q->where('l.user_id', $u->id));

        $run = $this->safe(fn () => DB::table('hr_payroll_runs')->where('company_id', $cid)->orderByDesc('id')->first(), null);
        $payroll = null;
        if ($run) {
            $sum = $this->safe(fn () => DB::table('hr_payroll_items')->where('payroll_run_id', $run->id)->selectRaw('COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(deductions),0) d, COALESCE(SUM(net_pay),0) n, COUNT(*) c')->first(), null);
            $payroll = ['period' => $run->period, 'status' => $run->status, 'gross' => (float) ($sum->g ?? 0), 'deductions' => (float) ($sum->d ?? 0), 'net' => (float) ($sum->n ?? 0), 'staff' => (int) ($sum->c ?? 0)];
        }

        return [
            'attendance' => ['staff' => $staff, 'present' => $in, 'on_time' => max(0, $in - $late), 'late' => $late, 'on_leave' => $onLeave, 'absent' => max(0, $staff - $in - $onLeave),
                'month_pct' => $team ? ($staff > 0 ? round($monthPresent / max(1, $staff * $daysSoFar) * 100, 1) : null) : round($monthPresent / $daysSoFar * 100, 1)],
            'leave' => [
                'pending' => $this->safe(fn () => $leave()->where('l.status', 'pending')->count(), 0),
                'requests' => $this->safe(fn () => $leave()->where('l.status', 'pending')->orderBy('l.start_date')->limit(3)
                    ->get(['l.id', 'u.name', 't.name as type', 'l.start_date', 'l.end_date', 'l.days'])
                    ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'type' => $r->type ?: 'Leave', 'from' => $r->start_date, 'to' => $r->end_date, 'days' => (float) $r->days])->all(), []),
            ],
            'payroll' => $payroll,
            'directory' => ['staff' => $staff, 'joining_soon' => $this->safe(fn () => DB::table('users')->where('company_id', $cid)->where('created_at', '>=', now()->startOfMonth()->toDateTimeString())->count(), 0)],
            'out_today' => $this->safe(fn () => DB::table('hr_attendance as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.company_id', $cid)->whereDate('a.work_date', $today)
                ->where(fn ($w) => $w->where('a.status', 'on_leave')->orWhere('a.late_minutes', '>', 0))->limit(6)
                ->get(['u.name', 'a.status', 'a.late_minutes', 'a.clock_in_at'])
                ->map(fn ($r) => ['name' => $r->name, 'status' => $r->status === 'on_leave' ? 'on_leave' : 'late', 'late_minutes' => (int) $r->late_minutes, 'clock_in_at' => $r->clock_in_at])->all(), []),
        ];
    }

    // ───────────────────────── Administrators ─────────────────────────
    private function administrators($u, bool $team): array
    {
        $cid = $this->cid();
        $company = $u->company()->with('plan')->first();
        $users = fn () => DB::table('users')->where('company_id', $cid);
        $used = $users()->where('is_active', true)->count();
        $limit = $company?->plan?->max_users;

        $devices = $this->safe(fn () => DB::table('user_devices as d')->join('users as x', 'x.id', '=', 'd.user_id')->where('d.company_id', $cid)->whereNull('d.revoked_at')
            ->orderByDesc('d.last_active_at')->limit(5)->get(['d.device_name', 'd.platform', 'd.last_active_at', 'x.name as user'])
            ->map(fn ($d) => ['name' => $d->device_name ?: ucfirst((string) $d->platform), 'user' => $d->user, 'last_active_at' => $d->last_active_at])->all(), []);

        $integrations = [];
        if ($this->has('openwa_sessions')) {
            $n = DB::table('openwa_sessions')->where('company_id', $cid)->count();
            $ready = DB::table('openwa_sessions')->where('company_id', $cid)->whereIn('status', ['ready', 'READY'])->count();
            if ($n) { $integrations[] = ['name' => 'WhatsApp (WA Chat)', 'detail' => "$ready of $n sessions connected", 'ok' => $ready === $n]; }
        }
        if ($this->has('instagram_accounts')) {
            $n = DB::table('instagram_accounts')->where('company_id', $cid)->whereNull('deleted_at')->count();
            $ok = DB::table('instagram_accounts')->where('company_id', $cid)->whereNull('deleted_at')->where('is_active', true)->count();
            if ($n) { $integrations[] = ['name' => 'Instagram & Messenger', 'detail' => "$ok of $n accounts linked", 'ok' => $ok === $n]; }
        }
        if ($this->has('google_integrations')) {
            $g = DB::table('google_integrations')->where('company_id', $cid)->first();
            if ($g) { $integrations[] = ['name' => 'Google', 'detail' => $g->google_email ?: 'Connected', 'ok' => (bool) $g->is_active && !$g->last_error]; }
        }
        if ($this->has('meta_ad_accounts')) {
            $n = DB::table('meta_ad_accounts')->where('company_id', $cid)->whereNull('deleted_at')->count();
            if ($n) { $integrations[] = ['name' => 'Meta Ads', 'detail' => "$n ad account" . ($n === 1 ? '' : 's'), 'ok' => true]; }
        }

        $activity = collect();
        $this->safe(function () use ($cid, $activity) {
            DB::table('users')->where('company_id', $cid)->orderByDesc('id')->limit(3)->get(['name', 'created_at'])->each(fn ($x) => $activity->push(['text' => "{$x->name} joined the team", 'at' => $x->created_at]));
            DB::table('hr_leave_requests as l')->join('users as x', 'x.id', '=', 'l.user_id')->where('l.company_id', $cid)->whereNotNull('l.reviewed_at')->orderByDesc('l.reviewed_at')->limit(3)
                ->get(['x.name', 'l.status', 'l.reviewed_at'])->each(fn ($x) => $activity->push(['text' => "{$x->name}'s leave was {$x->status}", 'at' => $x->reviewed_at]));
            DB::table('user_devices as d')->join('users as x', 'x.id', '=', 'd.user_id')->where('d.company_id', $cid)->orderByDesc('d.created_at')->limit(3)
                ->get(['x.name', 'd.device_name', 'd.created_at'])->each(fn ($x) => $activity->push(['text' => "{$x->name} signed in on " . ($x->device_name ?: 'a new device'), 'at' => $x->created_at]));
        });

        return [
            'plan' => ['name' => $company?->plan?->name, 'seats_used' => $used, 'seats_limit' => $limit, 'renews_at' => $company?->plan_expires_at?->toDateString()],
            'staff' => ['total' => $users()->count(), 'active' => $used, 'inactive' => $users()->where('is_active', false)->count()],
            'roles' => $this->safe(fn () => DB::table('roles')->where('company_id', $cid)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'label'])
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->label ?: $r->name, 'users' => DB::table('users')->where('company_id', $cid)->where('role_id', $r->id)->count()])->all(), []),
            'devices' => ['active' => $this->safe(fn () => DB::table('user_devices')->where('company_id', $cid)->whereNull('revoked_at')->count(), 0), 'list' => $devices],
            'integrations' => $integrations,
            'recent_activity' => $activity->sortByDesc('at')->take(5)->values()->all(),
        ];
    }

    // ───────────────────────────── Marketing ─────────────────────────────
    private function marketing($u, bool $team): array
    {
        $cid = $this->cid();
        $since = Carbon::today()->subDays(29)->toDateString();
        $ins = fn () => DB::table('meta_insights')->where('company_id', $cid)->where('object_type', 'campaign')->where('date', '>=', $since);
        $t = $this->safe(fn () => $ins()->selectRaw('COALESCE(SUM(spend),0) spend, COALESCE(SUM(impressions),0) imp, COALESCE(SUM(clicks),0) clk, COALESCE(SUM(leads),0) leads')->first(), null);
        $daily = $this->safe(fn () => $ins()->where('date', '>=', Carbon::today()->subDays(6)->toDateString())->selectRaw('date d, SUM(leads) v')->groupBy('date')->pluck('v', 'd')->all(), []);
        $series = [];
        for ($i = 6; $i >= 0; $i--) { $d = Carbon::today()->subDays($i); $series[] = ['date' => $d->toDateString(), 'day' => $d->format('D')[0], 'leads' => (int) ($daily[$d->toDateString()] ?? 0)]; }

        $live = [];
        $this->safe(function () use ($cid, &$live) {
            $names = DB::table('meta_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'ACTIVE')->limit(3)->get(['id', 'name', 'objective']);
            foreach ($names as $c) {
                $s = DB::table('meta_insights')->where('company_id', $cid)->where('object_type', 'campaign')->where('object_id', $c->id)->where('date', '>=', Carbon::today()->subDays(29)->toDateString())
                    ->selectRaw('COALESCE(SUM(spend),0) spend, COALESCE(SUM(clicks),0) clk, COALESCE(SUM(leads),0) leads, COALESCE(SUM(impressions),0) imp')->first();
                $live[] = ['type' => 'Meta ads', 'name' => $c->name, 'a' => ['label' => 'Spend', 'value' => (float) $s->spend, 'money' => true], 'b' => ['label' => 'Clicks', 'value' => (int) $s->clk], 'leads' => (int) $s->leads];
            }
            foreach (DB::table('openwa_message_sender_jobs')->where('company_id', $cid)->whereIn('status', ['running', 'scheduled', 'paused'])->orderByDesc('id')->limit(3)->get() as $j) {
                $live[] = ['type' => 'WhatsApp', 'name' => $j->campaign_name ?: "Campaign #{$j->id}", 'a' => ['label' => 'Sent', 'value' => (int) $j->sent], 'b' => ['label' => 'Total', 'value' => (int) $j->total],
                    'progress' => $j->total > 0 ? round($j->sent / $j->total, 3) : 0, 'status' => $j->status];
            }
        });

        $templates = $this->has('wa_templates') ? DB::table('wa_templates')->where('company_id', $cid) : null;

        return [
            'leads_30d' => (int) ($t->leads ?? 0), 'spend_30d' => (float) ($t->spend ?? 0),
            'cost_per_lead' => ($t->leads ?? 0) > 0 ? round($t->spend / $t->leads, 2) : null,
            'ctr' => ($t->imp ?? 0) > 0 ? round($t->clk / $t->imp * 100, 2) : null,
            'daily_leads' => $series,
            'tiles' => [
                'campaigns' => ['live' => $this->safe(fn () => DB::table('meta_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'ACTIVE')->count(), 0)
                    + $this->safe(fn () => DB::table('openwa_message_sender_jobs')->where('company_id', $cid)->where('status', 'running')->count(), 0)],
                'templates' => ['total' => $this->safe(fn () => $templates?->count() ?? 0, 0), 'approved' => $this->safe(fn () => $templates ? (clone $templates)->whereRaw("LOWER(status) = 'approved'")->count() : 0, 0)],
                'flows' => ['total' => $this->safe(fn () => DB::table('instagram_flows')->where('company_id', $cid)->whereNull('deleted_at')->count(), 0), 'running' => $this->safe(fn () => DB::table('instagram_flows')->where('company_id', $cid)->whereNull('deleted_at')->where('is_active', true)->count(), 0)],
                'social' => ['accounts' => $this->safe(fn () => DB::table('instagram_accounts')->where('company_id', $cid)->whereNull('deleted_at')->count(), 0)],
            ],
            'live_campaigns' => $live,
            'flows' => $this->safe(fn () => DB::table('instagram_flows')->where('company_id', $cid)->whereNull('deleted_at')->orderByDesc('triggered_count')->limit(4)->get(['id', 'name', 'is_active', 'triggered_count'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'active' => (bool) $f->is_active, 'runs' => (int) $f->triggered_count])->all(), []),
        ];
    }

    // ───────────────────────────── Accounts ─────────────────────────────
    private function accounts($u, bool $team): array
    {
        $cid = $this->cid();
        $wallet = Wallet::where('company_id', $cid)->first();
        $month = now()->startOfMonth()->toDateTimeString();
        $spent = $this->safe(fn () => DB::table('wallet_transactions')->where('company_id', $cid)->where('type', 'debit')->where('created_at', '>=', $month)->sum('amount'), 0);

        $recent = $this->safe(fn () => DB::table('acc_vouchers')->where('company_id', $cid)->where('is_cancelled', false)->orderByDesc('date')->orderByDesc('id')->limit(4)
            ->get(['id', 'type', 'number', 'date', 'total', 'narration'])
            ->map(fn ($v) => ['id' => $v->id, 'type' => $v->type, 'number' => $v->number, 'date' => $v->date, 'amount' => (float) $v->total,
                'direction' => in_array($v->type, ['receipt', 'sales', 'invoice', 'sale'], true) ? 'in' : 'out', 'title' => $v->narration ?: ucfirst((string) $v->type) . ' ' . $v->number])->all(), []);

        return [
            'wallet' => ['balance' => (float) ($wallet->balance ?? 0), 'spent_month' => (float) $spent,
                'by_source' => $this->safe(fn () => DB::table('wa_chat_charges')->where('company_id', $cid)->where('status', 'charged')->where('updated_at', '>=', $month)->selectRaw('source, SUM(credits) c')->groupBy('source')->pluck('c', 'source')->all(), [])],
            'recent_entries' => $recent,
            'books' => ['entries_month' => $this->safe(fn () => DB::table('acc_vouchers')->where('company_id', $cid)->where('is_cancelled', false)->where('date', '>=', now()->startOfMonth()->toDateString())->count(), 0),
                'ledgers' => $this->safe(fn () => DB::table('acc_ledgers')->where('company_id', $cid)->count(), 0)],
        ];
    }
}
