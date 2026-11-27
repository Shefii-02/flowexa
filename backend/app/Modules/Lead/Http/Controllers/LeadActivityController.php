<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeadActivity;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Calls / messages / meetings staff log on a lead — the raw data behind the daily sales-activity monitor. */
class LeadActivityController extends Controller
{
    public function __construct(private readonly LeadService $leads, private readonly LeadRepositoryInterface $repo) {}

    private function lead(int $id)
    {
        $u = auth()->user();
        return $this->leads->show($id, $u->company_id, $u->id, $u->hasPermission('leads.view_all'));   // 404 / 403 exactly like viewing the lead
    }

    /** GET /leads/{lead}/activities */
    public function index(int $lead): JsonResponse
    {
        $l = $this->lead($lead);
        $rows = LeadActivity::where('lead_id', $l->id)->with('user:id,name')->orderByDesc('happened_at')->orderByDesc('id')->limit(100)->get();
        return response()->json(['data' => $rows->map(fn ($a) => [
            'id' => $a->id, 'kind' => $a->kind, 'direction' => $a->direction, 'outcome' => $a->outcome, 'first' => $a->is_first_contact, 'duration_seconds' => $a->duration_seconds,
            'notes' => $a->notes, 'next_follow_up_at' => $a->next_follow_up_at?->toIso8601String(), 'happened_at' => $a->happened_at->toIso8601String(), 'by' => $a->user?->name,
        ])]);
    }

    /** GET /leads/call-logs?scope=mine|all&kind=call&q= — recent calls (or other logged activity) across leads: mine by default; everyone's for those who see all leads. */
    public function callLogs(Request $request): JsonResponse
    {
        $u = auth()->user();
        $all = $request->query('scope') === 'all' && $u->hasPermission('leads.view_all');
        $kind = $request->query('kind', 'call');
        // everything except the direction / status picks, so the chips can show how many calls each would give
        $base = fn () => DB::table('lead_activities as a')
            ->join('leads as l', 'l.id', '=', 'a.lead_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.company_id', $u->company_id)->whereNull('l.deleted_at')
            ->when($kind !== 'all', fn ($x) => $x->where('a.kind', $kind))
            ->when(!$all, fn ($x) => $x->where('a.user_id', $u->id))
            ->when($request->filled('user_id') && $all, fn ($x) => $x->where('a.user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($x) => $x->where('a.happened_at', '>=', Carbon::parse($request->string('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($x) => $x->where('a.happened_at', '<=', Carbon::parse($request->string('to'))->endOfDay()))
            ->when($request->filled('q'), function ($x) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $x->where(fn ($w) => $w->where('c.name', 'like', $t)->orWhere('c.phone', 'like', $t));
            });

        $rows = $base()
            ->when($request->filled('direction'), fn ($x) => $x->where('a.direction', $request->string('direction')))
            // "received" = a call that was answered: incoming and connected
            ->when($request->query('status') === 'received', fn ($x) => $x->where('a.direction', 'incoming')->where('a.outcome', 'connected'))
            ->when($request->query('status') === 'not_reached', fn ($x) => $x->where('a.outcome', 'not_reachable'))
            ->when($request->filled('outcome'), fn ($x) => $x->where('a.outcome', $request->string('outcome')))
            ->orderByDesc('a.happened_at')->orderByDesc('a.id')->limit(300)
            ->get(['a.id', 'a.lead_id', 'a.kind', 'a.direction', 'a.outcome', 'a.duration_seconds', 'a.notes', 'a.next_follow_up_at', 'a.happened_at', 'c.name as contact_name', 'c.phone as contact_phone', 'u.name as by']);

        $byDir = $base()->selectRaw('a.direction, count(*) as n')->groupBy('a.direction')->pluck('n', 'direction');
        $received = $base()->where('a.direction', 'incoming')->where('a.outcome', 'connected')->count();

        return response()->json(['data' => $rows, 'scope' => $all ? 'all' : 'mine', 'counts' => [
            'all' => (int) $byDir->sum(), 'incoming' => (int) ($byDir['incoming'] ?? 0), 'outgoing' => (int) ($byDir['outgoing'] ?? 0),
            'missed' => (int) ($byDir['missed'] ?? 0), 'received' => $received,
        ]]);
    }

    /** POST /leads/{lead}/activities — log a call (or message / meeting). */
    public function store(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate([
            'kind'              => ['nullable', 'in:' . implode(',', LeadActivity::KINDS)],
            'direction'         => ['nullable', 'in:' . implode(',', LeadActivity::DIRECTIONS)],
            'outcome'           => ['nullable', 'in:' . implode(',', LeadActivity::OUTCOMES)],
            'duration_seconds'  => ['nullable', 'integer', 'min:0', 'max:86400'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
            'stage'             => ['nullable', 'in:new,contacted,follow_up,enrolled,lost'],
            'happened_at'       => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $l = $this->lead($lead);
        $user = auth()->user();
        $kind = $d['kind'] ?? 'call';
        if (!empty($d['stage'])) {
            // closing or reopening from the call form needs the same note — the call's notes are it
            try { \App\Modules\Lead\Services\LeadService::assertStageNote($l->stage, $d['stage'], $d['notes'] ?? null); }
            catch (\App\Modules\Lead\Exceptions\LeadException $e) { throw \Illuminate\Validation\ValidationException::withMessages(['notes' => $e->getMessage()]); }
        }

        $activity = DB::transaction(function () use ($l, $user, $d, $kind) {
            \App\Models\Lead::whereKey($l->id)->lockForUpdate()->first();   // two calls logged at once must not both count as the first
            $first = !LeadActivity::where('lead_id', $l->id)->exists();
            $a = LeadActivity::create([
                'company_id' => $l->company_id, 'lead_id' => $l->id, 'user_id' => $user->id, 'kind' => $kind, 'direction' => $d['direction'] ?? 'outgoing',
                'outcome' => $d['outcome'] ?? null, 'is_first_contact' => $first, 'duration_seconds' => $d['duration_seconds'] ?? null,
                'notes' => $d['notes'] ?? null, 'next_follow_up_at' => $d['next_follow_up_at'] ?? null,
                'happened_at' => isset($d['happened_at']) ? Carbon::parse($d['happened_at']) : now(),
            ]);

            // keep the pipeline in step: an explicit stage wins; otherwise reaching a new lead → Contacted, a booked callback → Follow-up
            $stage = $d['stage'] ?? null;
            if (!$stage && $l->stage === 'new' && in_array($d['outcome'] ?? null, LeadActivity::REACHED, true)) { $stage = 'contacted'; }
            if (!$stage && !empty($d['next_follow_up_at']) && in_array($l->stage, ['new', 'contacted'], true)) { $stage = 'follow_up'; }
            $this->repo->update($l, new UpdateLeadDTO(stage: $stage && $stage !== $l->stage ? $stage : null, followedUpAt: $a->happened_at, reason: $d['notes'] ?? null));
            $this->repo->logEvent($l, $kind . '_logged', ['activity_id' => $a->id, 'outcome' => $a->outcome, 'first' => $first, 'notes' => $a->notes]);
            return $a;
        });

        return response()->json(['message' => ucfirst($kind) . ' logged.', 'data' => ['id' => $activity->id, 'first' => $activity->is_first_contact, 'stage' => $l->fresh()->stage]], 201);
    }

    /** PUT /leads/{lead}/cost — what this lead cost to get (ad spend etc.); feeds cost per converted lead. */
    public function cost(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate(['cost' => ['nullable', 'numeric', 'min:0', 'max:99999999']]);
        $l = $this->lead($lead);
        $l->update(['cost' => $d['cost'] ?? null]);
        return response()->json(['message' => 'Lead cost saved.', 'cost' => $l->fresh()->cost]);
    }

    /**
     * GET /leads/{lead}/campaigns — this contact's WhatsApp campaign history: how many campaigns were sent to this number, how many of those
     * got a reply, plus one row per campaign. "Responded" = the contact sent us a message within 7 days after that campaign message.
     */
    public function campaigns(int $lead): JsonResponse
    {
        $l = $this->lead($lead);
        $cid = (int) auth()->user()->company_id;
        $digits = preg_replace('/\D+/', '', (string) ($l->contact?->phone ?? ''));
        $tail = strlen($digits) >= 10 ? substr($digits, -10) : $digits;
        if ($l->contact && !$l->contact->phoneLooksReal()) { $tail = ''; }   // a WhatsApp privacy id is not a phone number — match by contact only

        $rows = DB::table('campaign_contacts as cc')->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('c.company_id', $cid)
            ->where(fn ($q) => $q->when($l->contact_id, fn ($w) => $w->where('cc.contact_id', $l->contact_id))
                ->when($tail !== '', fn ($w) => $w->orWhere('cc.phone', 'like', '%' . $tail)))
            ->orderByDesc(DB::raw('COALESCE(cc.sent_at, cc.created_at)'))->limit(200)
            ->get(['cc.id', 'cc.campaign_id', 'c.name', 'cc.status', 'cc.sent_at', 'cc.delivered_at', 'cc.read_at', 'cc.failed_reason', 'cc.pricing_category']);

        // everything this contact sent us (newest 1000), compared against each campaign send time
        $inbound = DB::table('message_logs')->where('company_id', $cid)->where('direction', 'inbound')
            ->where(fn ($q) => $q->when($l->contact_id, fn ($w) => $w->where('contact_id', $l->contact_id))
                ->when($tail !== '', fn ($w) => $w->orWhere('phone', 'like', '%' . $tail)))
            ->orderByDesc('created_at')->limit(1000)->pluck('created_at')->map(fn ($t) => Carbon::parse($t))->all();

        $items = $rows->map(function ($r) use ($inbound) {
            $sent = $r->sent_at ? Carbon::parse($r->sent_at) : null;
            $replies = $sent ? array_values(array_filter($inbound, fn ($t) => $t->gte($sent) && $t->lte($sent->copy()->addDays(7)))) : [];
            usort($replies, fn ($a, $b) => $a <=> $b);

            return ['id' => $r->id, 'campaign_id' => $r->campaign_id, 'name' => $r->name, 'status' => $r->status, 'category' => $r->pricing_category,
                'sent_at' => $sent?->toIso8601String(), 'delivered_at' => $r->delivered_at ? Carbon::parse($r->delivered_at)->toIso8601String() : null,
                'read_at' => $r->read_at ? Carbon::parse($r->read_at)->toIso8601String() : null, 'failed_reason' => $r->failed_reason,
                'responded' => count($replies) > 0, 'replies' => count($replies), 'first_reply_at' => $replies ? $replies[0]->toIso8601String() : null];
        })->values();

        $sentRows = $items->filter(fn ($i) => $i['sent_at'] !== null);

        return response()->json([
            'summary' => [
                'campaigns_sent' => $sentRows->pluck('campaign_id')->unique()->count(),
                'messages_sent' => $sentRows->count(),
                'delivered' => $items->filter(fn ($i) => $i['delivered_at'] !== null)->count(),
                'read' => $items->filter(fn ($i) => $i['read_at'] !== null)->count(),
                'failed' => $items->where('status', 'failed')->count(),
                'responded' => $sentRows->where('responded', true)->pluck('campaign_id')->unique()->count(),
                'response_rate' => $sentRows->count() ? round($sentRows->where('responded', true)->count() / $sentRows->count() * 100, 1) : 0,
                'last_sent_at' => $sentRows->first()['sent_at'] ?? null,
            ],
            'items' => $items,
            'note' => 'Responded = this contact messaged us within 7 days after the campaign message. WA Cloud campaigns only.',
        ]);
    }
}
