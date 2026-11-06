<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{InstagramAccount, InstagramFlow};
use App\Modules\Instagram\Flow\{FlowValidator, WizardCompiler};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The AutoDM wizard's API: automations are flows compiled from a wizard config (kept in `instagram_flows.wizard`). */
class InstagramAutoDmController extends Controller
{
    public function __construct(private readonly WizardCompiler $compiler, private readonly FlowValidator $validator) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->query()->whereNotNull('wizard')
            ->whereHas('account', fn ($q) => $q->where('channel', $request->query('channel', 'instagram')))
            ->when($request->account_id, fn ($q, $id) => $q->where('instagram_account_id', $id))
            ->withCount('runs')->orderByDesc('updated_at')->get()
            ->map(function ($f) {
                return $this->present($f) + ['runs' => $f->runs_count];
            });
        return response()->json(['automations' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['automation' => $this->present($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        [$account, $wizard, $draft] = $this->input($request);
        $flow = new InstagramFlow(['company_id' => auth()->user()->company_id, 'instagram_account_id' => $account->id]);
        $wizard = $this->forChannel($account, $wizard);
        return $this->persist($flow, $request->string('name')->toString(), $wizard, $draft, true);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $flow = $this->find($id);
        [$account, $wizard, $draft] = $this->input($request, $flow->instagram_account_id);
        return $this->persist($flow, $request->input('name', $flow->name), $this->forChannel($account, $wizard), $draft, false);
    }

    public function toggle(int $id): JsonResponse
    {
        $flow = $this->find($id);
        if ($flow->status !== 'enabled' && ($errors = $this->compiler->validate($flow->wizard ?? []))) {
            return response()->json(['message' => 'Fix the automation before enabling it.', 'errors' => $errors], 422);
        }
        $to = $flow->status === 'enabled' ? 'paused' : 'enabled';
        $flow->update(['status' => $to, 'is_active' => $to === 'enabled']);
        return response()->json(['automation' => $this->present($flow->fresh())]);
    }

    public function duplicate(int $id): JsonResponse
    {
        $copy = $this->find($id)->replicate(['triggered_count', 'last_triggered_at', 'dms_sent', 'leads', 'new_followers']);
        $copy->name = mb_substr($copy->name . ' (copy)', 0, 150);
        $copy->status = 'draft';
        $copy->is_active = false;
        $copy->save();
        return response()->json(['automation' => $this->present($copy)], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /** Per-contact timeline for the run drawer. */
    public function runs(int $id): JsonResponse
    {
        $flow = $this->find($id);
        $runs = $flow->runs()->with('conversation:id,participant_username,contact_id,lead_id,collected')->latest('id')->limit(100)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'status' => $r->status, 'error' => $r->error, 'started_at' => $r->created_at, 'finished_at' => $r->finished_at,
                'username' => $r->conversation?->participant_username, 'email' => ($r->conversation?->collected ?? [])['email'] ?? null,
                'lead_id' => $r->conversation?->lead_id, 'clicked' => (bool) $r->ctx('link_clicked'), 'followed' => (bool) $r->ctx('gained_follower'),
                'follow_attempts' => (int) $r->ctx('follow_tries', 0),
                'messages' => $r->conversation?->messages()->where('source', 'flow')->where('created_at', '>=', $r->created_at)
                    ->orderBy('id')->limit(30)->get(['direction', 'text', 'status', 'error', 'created_at']),
            ]);
        return response()->json(['runs' => $runs]);
    }

    /** KPI cards + sparkline: DMs sent, leads, followers gained. */
    public function stats(Request $request): JsonResponse
    {
        $days  = ['7d' => 7, '30d' => 30, '90d' => 90][$request->query('range', '30d')] ?? 30;
        $since = now()->subDays($days)->startOfDay();
        $cid   = auth()->user()->company_id;
        $accountIds = InstagramAccount::where('company_id', $cid)->where('channel', $request->query('channel', 'instagram'))->pluck('id');

        $dms = DB::table('instagram_messages as m')
            ->join('instagram_conversations as c', 'c.id', '=', 'm.instagram_conversation_id')
            ->whereIn('c.instagram_account_id', $accountIds)->where('m.direction', 'out')->where('m.source', 'flow')
            ->where('m.status', 'sent')->where('m.created_at', '>=', $since)
            ->selectRaw('DATE(m.created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $runs = DB::table('instagram_flow_runs')->where('company_id', $cid)->where('created_at', '>=', $since);
        $leads = DB::table('instagram_conversations')->whereIn('instagram_account_id', $accountIds)
            ->whereNotNull('lead_created_at')->where('lead_created_at', '>=', $since)
            ->selectRaw('DATE(lead_created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $gained = (clone $runs)->where('context->gained_follower', true)->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $clicks = (int) DB::table('instagram_link_clicks as l')->join('instagram_flows as f', 'f.id', '=', 'l.instagram_flow_id')
            ->where('f.company_id', $cid)->where('l.first_clicked_at', '>=', $since)->count();

        $series = fn ($m) => collect(range($days - 1, 0))->map(fn ($i) => (int) ($m[now()->subDays($i)->toDateString()] ?? 0))->all();
        return response()->json([
            'dms_sent'  => ['total' => (int) $dms->sum(), 'series' => $series($dms)],
            'leads'     => ['total' => (int) $leads->sum(), 'series' => $series($leads)],
            'followers' => ['total' => (int) $gained->sum(), 'series' => $series($gained)],
            'clicks'    => $clicks,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $flows = $this->query()->whereNotNull('wizard')
            ->whereHas('account', fn ($q) => $q->where('channel', $request->query('channel', 'instagram')))->withCount('runs')->get();
        return response()->streamDownload(function () use ($flows) {
            $o = fopen('php://output', 'w');
            fputcsv($o, ['Name', 'Trigger', 'Status', 'Runs', 'DMs sent', 'Clicks', 'Leads', 'New followers', 'Last modified']);
            foreach ($flows as $f) {
                $clicks = (int) DB::table('instagram_link_clicks')->where('instagram_flow_id', $f->id)->sum('clicks');
                fputcsv($o, [$f->name, $f->wizard['trigger_type'] ?? '', $f->status, $f->runs_count, $f->dms_sent, $clicks, $f->leads, $f->new_followers, $f->updated_at]);
            }
            fclose($o);
        }, 'instagram-autodm.csv', ['Content-Type' => 'text/csv']);
    }

    /** Link picker sources: custom URL plus Teamzo entities that exist for this workspace. */
    public function linkSources(): JsonResponse
    {
        $groups = [['key' => 'custom', 'label' => 'Custom URL', 'items' => []]];
        return response()->json(['sources' => $groups]);
    }

    // ── internals ──

    /** Messenger has no story replies, Live comments or follower check — normalise the wizard accordingly. */
    private function forChannel(InstagramAccount $account, array $w): array
    {
        if ($account->channel !== 'facebook') {
            return $w;
        }
        if (in_array($w['trigger_type'] ?? '', ['story_reply', 'live_comment'], true)) {
            abort(response()->json(['message' => 'That trigger is only available on Instagram.'], 422));
        }
        $w['follow_gate']['enabled'] = false;
        return $w;
    }

    private function input(Request $request, ?int $accountId = null): array
    {
        $d = $request->validate([
            'instagram_account_id' => [$accountId ? 'sometimes' : 'required', 'integer'],
            'name'     => ['sometimes', 'string', 'max:150'],
            'wizard'   => ['required', 'array'],
            'draft'    => ['sometimes', 'boolean'],
        ]);
        $account = InstagramAccount::where('company_id', auth()->user()->company_id)->findOrFail($accountId ?? $d['instagram_account_id']);
        return [$account, $d['wizard'], (bool) ($d['draft'] ?? false)];
    }

    private function persist(InstagramFlow $flow, string $name, array $wizard, bool $draft, bool $creating): JsonResponse
    {
        $errors = $this->compiler->validate($wizard);
        if (!$draft && $errors) {
            return response()->json(['message' => $errors[0], 'errors' => $errors], 422);
        }
        if ($creating && ($msg = $this->planLimit())) {
            return response()->json(['message' => $msg, 'error_code' => 'plan_limit_reached', 'resource' => 'instagram_automations'], 403);
        }

        $compiled = $this->compiler->compile($wizard + ['trigger_type' => $wizard['trigger_type'] ?? 'post_comment']);
        $flowErrors = $draft ? [] : array_merge($this->validator->structure($compiled['nodes'], $compiled['edges']), $this->validator->semantics($compiled['nodes'], $compiled['edges']));
        if ($flowErrors) {
            return response()->json(['message' => $flowErrors[0], 'errors' => $flowErrors], 422);
        }

        $status = $draft ? 'draft' : ($flow->status === 'paused' ? 'paused' : 'enabled');
        $flow->fill([
            'name' => $name ?: 'Untitled automation', 'wizard' => $wizard,
            'nodes' => $compiled['nodes'], 'edges' => $compiled['edges'], 'trigger_type' => $compiled['trigger_type'],
            'status' => $status, 'is_active' => $status === 'enabled',
        ])->save();
        return response()->json(['automation' => $this->present($flow->fresh()), 'errors' => $errors], $creating ? 201 : 200);
    }

    private function planLimit(): ?string
    {
        $limit = auth()->user()->company?->plan?->max_instagram_automations ?? null;
        if ($limit === null) return null;
        $n = $this->query()->whereNotNull('wizard')->count();
        return $n >= $limit ? "Your plan allows {$limit} Instagram automation(s). Upgrade your plan to add more." : null;
    }

    private function present(InstagramFlow $f): array
    {
        return [
            'id' => $f->id, 'instagram_account_id' => $f->instagram_account_id, 'name' => $f->name, 'status' => $f->status,
            'wizard' => $f->wizard, 'dms_sent' => $f->dms_sent, 'leads' => $f->leads, 'new_followers' => $f->new_followers,
            'clicks' => (int) DB::table('instagram_link_clicks')->where('instagram_flow_id', $f->id)->sum('clicks'),
            'updated_at' => $f->updated_at,
        ];
    }

    private function query()
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');
        return InstagramFlow::where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('instagram_account_id', $allowed));
    }

    private function find(int $id): InstagramFlow
    {
        return $this->query()->whereNotNull('wizard')->findOrFail($id);
    }
}
