<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HeldLead;
use App\Modules\Lead\Services\HeldLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Held leads: contacts captured while their source was switched off. Staff move them into Leads (one or many) or discard them. */
class HeldLeadController extends Controller
{
    public function __construct(private readonly HeldLeadService $held) {}

    private function filters(Request $r): array
    {
        return $r->validate(['status' => ['nullable', 'in:held,moved,discarded,all'], 'source' => ['nullable', 'string', 'max:30'], 'search' => ['nullable', 'string', 'max:100'], 'account' => ['nullable', 'regex:/^[a-z_]{3,30}:\d{1,12}$/'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
    }

    private function base(array $f)
    {
        return HeldLead::where('company_id', auth()->user()->company_id)
            ->when($f['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($f['account'] ?? null, function ($q, $a) {
                [$type, $id] = explode(':', $a);
                // a Meta ad campaign is the lead's ORIGIN; every other account type is the account it came through
                $type === 'ad_campaign' ? $q->where('origin_type', 'ad_campaign')->where('origin_id', (int) $id) : $q->where('account_type', $type)->where('account_id', (int) $id);
            })
            ->when($f['search'] ?? null, fn ($q, $s) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")));
    }

    /** GET /leads/held */
    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $status = $f['status'] ?? 'held';
        $summary = (clone $this->base($f))->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        $page = $this->base($f)->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['contact:id,name,phone,wa_id,email', 'handler:id,name'])
            ->orderByDesc('last_captured_at')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25));

        $page->getCollection()->transform(fn (HeldLead $h) => [
            'id' => $h->id, 'source' => $h->source, 'status' => $h->status, 'hits' => $h->hits,
            'contact' => ['id' => $h->contact?->id, 'name' => $h->contact?->name, 'phone' => $h->contact?->phone, 'phone_is_real' => $h->contact?->phoneLooksReal()],
            'origin_label' => $h->origin_label, 'held_reason' => $h->held_reason ?? 'source_off', 'account_type' => $h->account_type, 'account_id' => $h->account_id, 'category' => $h->category, 'notes' => $h->notes,
            'first_captured_at' => $h->first_captured_at?->toIso8601String(), 'last_captured_at' => $h->last_captured_at?->toIso8601String(),
            'lead_id' => $h->lead_id, 'result_note' => $h->result_note, 'handled_by' => $h->handler?->name, 'handled_at' => $h->handled_at?->toIso8601String(),
        ]);

        return response()->json($page->toArray() + ['summary' => ['held' => (int) ($summary['held'] ?? 0), 'moved' => (int) ($summary['moved'] ?? 0), 'discarded' => (int) ($summary['discarded'] ?? 0)]]);
    }

    /** POST /leads/held/move { ids: [] } or { all_matching: true, source?, search? } */
    public function move(Request $request): JsonResponse
    {
        $rows = $this->selected($request);
        $moved = 0; $dup = 0;
        foreach ($rows as $h) {
            $r = $this->held->move($h, (int) auth()->id());
            $r['status'] === 'moved' ? $moved++ : $dup++;
        }
        return response()->json(['moved' => $moved, 'skipped' => $dup, 'message' => "{$moved} lead(s) moved into Leads" . ($dup ? ", {$dup} skipped (already have an open lead)." : '.')]);
    }

    /** POST /leads/held/discard { ids: [] } or { all_matching: true, … } */
    public function discard(Request $request): JsonResponse
    {
        $rows = $this->selected($request);
        foreach ($rows as $h) { $this->held->discard($h, (int) auth()->id()); }
        return response()->json(['count' => count($rows), 'message' => count($rows) . ' held lead(s) discarded.']);
    }

    /** @return \Illuminate\Support\Collection<int,HeldLead> */
    private function selected(Request $request)
    {
        $d = $request->validate(['ids' => ['nullable', 'array', 'max:2000'], 'ids.*' => ['integer'], 'all_matching' => ['nullable', 'boolean']]);
        abort_if(empty($d['ids']) && empty($d['all_matching']), 422, 'Select at least one held lead.');
        $q = !empty($d['all_matching']) ? $this->base($this->filters($request)) : HeldLead::where('company_id', auth()->user()->company_id)->whereIn('id', $d['ids']);
        return $q->where('status', 'held')->orderBy('id')->limit(2000)->get();
    }
}
