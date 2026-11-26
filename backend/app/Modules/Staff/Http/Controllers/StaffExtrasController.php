<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;

/**
 * Staff profile extras kept next to the staff form (all need staff.manage):
 *   designations list · document types list · a person's documents (upload / delete) ·
 *   PUT /staff/{id}/extras — designation, joining date and "accessible users" (whose leads this person may also see).
 */
class StaffExtrasController extends Controller
{
    private const DEFAULT_DOCUMENT_TYPES = ['ID proof', 'Address proof', 'Education certificate', 'Experience letter', 'Offer / contract', 'Other'];

    private function cid(): int { return (int) auth()->user()->company_id; }

    private function person(int $id): User { return User::where('company_id', $this->cid())->findOrFail($id); }

    // ── designations ────────────────────────────────────────────────────────────
    public function designations(): JsonResponse
    {
        return response()->json(['data' => DB::table('staff_designations')->where('company_id', $this->cid())->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeDesignation(Request $r): JsonResponse
    {
        $name = trim($r->validate(['name' => ['required', 'string', 'max:100']])['name']);
        DB::table('staff_designations')->updateOrInsert(['company_id' => $this->cid(), 'name' => $name], ['updated_at' => now(), 'created_at' => now()]);

        return $this->designations();
    }

    public function destroyDesignation(int $id): JsonResponse
    {
        DB::table('staff_designations')->where('company_id', $this->cid())->where('id', $id)->delete();

        return $this->designations();
    }

    // ── document types ─────────────────────────────────────────────────────────
    public function documentTypes(): JsonResponse
    {
        $t = fn () => DB::table('staff_document_types')->where('company_id', $this->cid());
        if (! $t()->exists()) {   // first use: seed the usual ones
            foreach (self::DEFAULT_DOCUMENT_TYPES as $n) {
                DB::table('staff_document_types')->insert(['company_id' => $this->cid(), 'name' => $n, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return response()->json(['data' => $t()->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeDocumentType(Request $r): JsonResponse
    {
        $name = trim($r->validate(['name' => ['required', 'string', 'max:30']])['name']);   // hr_staff_documents.category is 30 chars
        DB::table('staff_document_types')->updateOrInsert(['company_id' => $this->cid(), 'name' => $name], ['updated_at' => now(), 'created_at' => now()]);

        return $this->documentTypes();
    }

    public function destroyDocumentType(int $id): JsonResponse
    {
        DB::table('staff_document_types')->where('company_id', $this->cid())->where('id', $id)->delete();

        return $this->documentTypes();
    }

    // ── a person's documents ───────────────────────────────────────────────────
    public function documents(int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $rows = DB::table('hr_staff_documents')->where('company_id', $this->cid())->where('user_id', $u->id)->orderByDesc('id')->get()
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'type' => $d->category, 'mime' => $d->mime, 'size' => (int) $d->size,
                'url' => Storage::disk('public')->url($d->path), 'created_at' => $d->created_at]);

        return response()->json(['data' => $rows]);
    }

    public function uploadDocument(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate([
            'type' => ['required', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);
        $file = $r->file('file');
        DB::table('hr_staff_documents')->insert([
            'company_id' => $this->cid(), 'user_id' => $u->id, 'title' => $d['title'] ?: $d['type'], 'category' => $d['type'],
            'path' => $file->store("staff-documents/{$this->cid()}/{$u->id}", 'public'), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->documents($staff);
    }

    public function destroyDocument(int $staff, int $id): JsonResponse
    {
        $u = $this->person($staff);
        $doc = DB::table('hr_staff_documents')->where('company_id', $this->cid())->where('user_id', $u->id)->where('id', $id)->first();
        abort_unless($doc, 404);
        Storage::disk('public')->delete($doc->path);
        DB::table('hr_staff_documents')->where('id', $id)->delete();

        return $this->documents($staff);
    }

    // ── designation / joining date / accessible users ──────────────────────────
    public function show(int $staff): JsonResponse
    {
        $u = $this->person($staff);

        return response()->json(['designation' => $u->designation, 'joined_on' => $u->joined_on?->toDateString(), 'accessible_user_ids' => array_values((array) ($u->accessible_user_ids ?? []))]);
    }

    public function update(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate([
            'designation' => ['nullable', 'string', 'max:100'],
            'joined_on' => ['nullable', 'date'],
            'accessible_user_ids' => ['nullable', 'array', 'max:200'],
            'accessible_user_ids.*' => ['integer'],
        ]);
        $ids = User::where('company_id', $this->cid())->whereIn('id', $d['accessible_user_ids'] ?? [])->where('id', '!=', $u->id)->pluck('id')->all();
        $u->update(['designation' => $d['designation'] ?? null, 'joined_on' => $d['joined_on'] ?? null, 'accessible_user_ids' => $ids]);
        if (! empty($d['designation'])) {   // a designation typed in once joins the company's list
            DB::table('staff_designations')->updateOrInsert(['company_id' => $this->cid(), 'name' => trim($d['designation'])], ['updated_at' => now(), 'created_at' => now()]);
        }

        return $this->show($staff);
    }



    // ── salary, sales targets, category targets + incentive % ──────────────────
    private function comp(): \App\Modules\Hr\Support\StaffCompensation { return app(\App\Modules\Hr\Support\StaffCompensation::class); }

    private function salaryPayload(int $staff): array
    {
        $u = $this->person($staff);
        $profile = \App\Modules\Hr\Models\HrStaffProfile::forUser($this->cid(), $u->id);
        $now = $this->comp()->salaryFor($profile, now());

        return ['current' => $now, 'history' => $this->comp()->salaryHistory($this->cid(), $u->id)];
    }

    public function salary(int $staff): JsonResponse { return response()->json($this->salaryPayload($staff)); }

    public function updateSalary(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate([
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'hourly_rate'    => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'effective_from' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'note'           => ['nullable', 'string', 'max:255'],
        ]);
        $this->comp()->setSalary($this->cid(), $u->id, isset($d['monthly_salary']) ? (float) $d['monthly_salary'] : null, isset($d['hourly_rate']) ? (float) $d['hourly_rate'] : null,
            $d['effective_from'] ?? null, auth()->id(), $d['note'] ?? null, 'staff');

        return response()->json($this->salaryPayload($staff));
    }

    private function targetsPayload(int $staff, ?string $month = null): array
    {
        $u = $this->person($staff);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $month) ? $month : now()->format('Y-m');
        $categories = DB::table('lead_categories')->where('company_id', $this->cid())->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name');

        return [
            'month' => $month,
            'effective_target' => $this->comp()->monthlyTarget($this->cid(), $u->id, $month),
            'targets' => $this->comp()->targets($this->cid(), $u->id),
            'category_rows' => $this->comp()->categoryRows($this->cid(), $u->id),
            'category_progress' => $this->comp()->categoryProgress($this->cid(), $u->id, $month),
            'categories' => $categories,
        ];
    }

    public function targets(Request $r, int $staff): JsonResponse { return response()->json($this->targetsPayload($staff, $r->query('month'))); }

    /** PUT {month: YYYY-MM | null (= every month), monthly_target} */
    public function setTarget(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'monthly_target' => ['required', 'numeric', 'min:0', 'max:99999999999']]);
        $this->comp()->setMonthlyTarget($this->cid(), $u->id, $d['month'] ?? null, (float) $d['monthly_target']);

        return response()->json($this->targetsPayload($staff, $d['month'] ?? null));
    }

    public function destroyTarget(int $staff, int $id): JsonResponse
    {
        $u = $this->person($staff);
        DB::table('hr_staff_targets')->where('company_id', $this->cid())->where('user_id', $u->id)->where('id', $id)->whereNotNull('month')->delete();   // the every-month target is changed, not removed

        return response()->json($this->targetsPayload($staff));
    }

    /** PUT {category, month: YYYY-MM | null (= every month), target_amount, incentive_percent} — adds or updates that row */
    public function setCategoryTarget(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate([
            'category' => ['required', 'string', 'max:100'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'target_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'incentive_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $key = ['user_id' => $u->id, 'category' => $d['category'], 'month' => $d['month'] ?? null];
        $q = DB::table('hr_category_targets')->where('user_id', $u->id)->where('category', $d['category']);
        ($d['month'] ?? null) === null ? $q->whereNull('month') : $q->where('month', $d['month']);
        $vals = ['target_amount' => (float) ($d['target_amount'] ?? 0), 'incentive_percent' => (float) ($d['incentive_percent'] ?? 0), 'updated_at' => now()];
        if ($q->exists()) { $q->update($vals); } else { DB::table('hr_category_targets')->insert($key + $vals + ['company_id' => $this->cid(), 'created_at' => now()]); }

        return response()->json($this->targetsPayload($staff, $d['month'] ?? null));
    }

    public function destroyCategoryTarget(Request $r, int $staff, int $id): JsonResponse
    {
        $u = $this->person($staff);
        DB::table('hr_category_targets')->where('company_id', $this->cid())->where('user_id', $u->id)->where('id', $id)->delete();

        return response()->json($this->targetsPayload($staff, $r->query('month')));
    }


    // ── leads, performance, activity ───────────────────────────────────────────

    /** GET /staff/{staff}/leads?month=YYYY-MM — this person's leads by stage, the month's movement, follow-ups and the latest leads. */
    public function leadsSummary(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $cid = $this->cid();
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('month')) ? (string) $r->query('month') : now()->format('Y-m');
        $from = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $to = $from->copy()->endOfMonth();
        $leads = fn () => DB::table('leads')->where('company_id', $cid)->where('assigned_to', $u->id)->whereNull('deleted_at');

        $byStage = $leads()->selectRaw('stage, COUNT(*) c')->groupBy('stage')->pluck('c', 'stage');
        $stages = [];
        foreach (['new', 'contacted', 'follow_up', 'enrolled', 'lost'] as $s) { $stages[$s] = (int) ($byStage[$s] ?? 0); }

        $open = fn () => $leads()->whereNotIn('stage', ['enrolled', 'lost']);
        $dueQ = fn () => DB::table('lead_activities as a')->join('leads as l', 'l.id', '=', 'a.lead_id')->where('a.company_id', $cid)->where('l.assigned_to', $u->id)
            ->whereNull('l.deleted_at')->whereNotIn('l.stage', ['enrolled', 'lost']);

        $recent = $leads()->leftJoin('contacts as c', 'c.id', '=', 'leads.contact_id')->orderByDesc('leads.updated_at')->limit(15)
            ->get(['leads.id', 'leads.stage', 'leads.category', 'leads.sale_value', 'leads.updated_at', 'leads.enrolled_at', 'leads.lost_at', 'c.name as contact_name', 'c.phone as contact_phone']);

        return response()->json([
            'month' => $month,
            'total' => array_sum($stages),
            'by_stage' => $stages,
            'month_stats' => [
                'assigned' => $leads()->whereBetween('assigned_at', [$from, $to])->count(),
                'enrolled' => $leads()->whereBetween('enrolled_at', [$from, $to])->count(),
                'lost' => $leads()->whereBetween('lost_at', [$from, $to])->count(),
                'sale_value' => round((float) $leads()->whereBetween('enrolled_at', [$from, $to])->sum('sale_value'), 2),
                'calls' => (int) DB::table('lead_activities')->where('company_id', $cid)->where('user_id', $u->id)->whereBetween('happened_at', [$from, $to])->count(),
            ],
            'followups' => [
                'due_today' => (int) $dueQ()->whereBetween('a.next_follow_up_at', [today()->startOfDay(), today()->endOfDay()])->distinct()->count('l.id'),
                'overdue' => (int) $dueQ()->where('a.next_follow_up_at', '<', today()->startOfDay())->distinct()->count('l.id'),
                'upcoming' => (int) $dueQ()->where('a.next_follow_up_at', '>', today()->endOfDay())->distinct()->count('l.id'),
            ],
            'open' => $open()->count(),
            'recent' => $recent,
        ]);
    }

    /** GET /staff/{staff}/performance?months=6 — month by month: attendance, sales vs target, leads, tasks and incentives; plus the incentive list. */
    public function performance(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $cid = $this->cid();
        $n = max(2, min(12, (int) $r->query('months', 6)));
        $comp = $this->comp();
        $rows = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $from = $m->copy(); $to = $m->copy()->endOfMonth(); $ym = $m->format('Y-m');
            $att = DB::table('hr_attendance')->where('company_id', $cid)->where('user_id', $u->id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();
            $sold = (float) DB::table('listing_sales')->where('company_id', $cid)->where('staff_id', $u->id)->whereNull('deleted_at')->whereBetween('sold_at', [$from->toDateString(), $to->toDateString()])->sum('amount');
            $target = $comp->monthlyTarget($cid, $u->id, $ym);
            $leads = fn () => DB::table('leads')->where('company_id', $cid)->where('assigned_to', $u->id)->whereNull('deleted_at');
            $inc = DB::table('hr_incentives')->where('company_id', $cid)->where('user_id', $u->id)->whereBetween('earned_on', [$from->toDateString(), $to->toDateString()]);
            $rows[] = [
                'month' => $ym, 'label' => $m->format('M y'),
                'present' => $att->where('status', 'present')->count(), 'late_days' => $att->where('late_minutes', '>', 0)->count(), 'absent' => $att->where('status', 'absent')->count(),
                'worked_hours' => round($att->sum('worked_minutes') / 60, 1),
                'sold' => round($sold, 2), 'target' => $target, 'achieved_pct' => $target > 0 ? round(min($sold / $target * 100, 999), 1) : null,
                'leads_assigned' => $leads()->whereBetween('assigned_at', [$from, $to])->count(),
                'leads_enrolled' => $leads()->whereBetween('enrolled_at', [$from, $to])->count(),
                'leads_lost' => $leads()->whereBetween('lost_at', [$from, $to])->count(),
                'tasks_done' => (int) DB::table('crm_tasks')->where('company_id', $cid)->where('assigned_to', $u->id)->where('status', 'done')->whereBetween('completed_at', [$from, $to])->count(),
                'incentive' => round((float) (clone $inc)->sum('amount'), 2),
            ];
        }
        $list = DB::table('hr_incentives')->where('company_id', $cid)->where('user_id', $u->id)->orderByDesc('earned_on')->orderByDesc('id')->limit(30)
            ->get(['id', 'title', 'base_amount', 'amount', 'earned_on', 'status', 'source_type']);
        $totals = DB::table('hr_incentives')->where('company_id', $cid)->where('user_id', $u->id)->selectRaw('status, COALESCE(SUM(amount),0) s')->groupBy('status')->pluck('s', 'status');

        return response()->json(['months' => $rows, 'incentives' => $list, 'incentive_totals' => [
            'pending' => (float) ($totals['pending'] ?? 0), 'approved' => (float) ($totals['approved'] ?? 0), 'paid' => (float) ($totals['paid'] ?? 0)]]);
    }

    /** GET /staff/{staff}/activity — last login, linked devices and one merged feed of what this person did and what was changed about them. */
    public function activity(int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $cid = $this->cid();
        $feed = collect();

        DB::table('lead_activities as a')->leftJoin('leads as l', 'l.id', '=', 'a.lead_id')->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('a.company_id', $cid)->where('a.user_id', $u->id)->orderByDesc('a.happened_at')->limit(40)
            ->get(['a.kind', 'a.outcome', 'a.notes', 'a.happened_at', 'a.lead_id', 'c.name as contact'])
            ->each(fn ($x) => $feed->push(['at' => $x->happened_at, 'type' => 'lead activity', 'text' => ucfirst($x->kind) . ($x->outcome ? ' — ' . str_replace('_', ' ', $x->outcome) : '') . ($x->contact ? " · {$x->contact}" : ''), 'detail' => $x->notes, 'lead_id' => $x->lead_id]));

        DB::table('lead_events as e')->leftJoin('leads as l', 'l.id', '=', 'e.lead_id')->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('e.company_id', $cid)->where('e.user_id', $u->id)->orderByDesc('e.created_at')->limit(40)
            ->get(['e.event', 'e.payload', 'e.created_at', 'e.lead_id', 'c.name as contact'])
            ->each(function ($x) use ($feed) {
                $p = json_decode((string) $x->payload, true) ?: [];
                $feed->push(['at' => $x->created_at, 'type' => 'lead', 'text' => ucfirst(str_replace('_', ' ', $x->event)) . (isset($p['from'], $p['to']) ? " ({$p['from']} → {$p['to']})" : '') . ($x->contact ? " · {$x->contact}" : ''), 'detail' => $p['reason'] ?? null, 'lead_id' => $x->lead_id]);
            });

        DB::table('hr_salary_history as h')->leftJoin('users as b', 'b.id', '=', 'h.changed_by')->where('h.company_id', $cid)->where('h.user_id', $u->id)->orderByDesc('h.id')->limit(20)
            ->get(['h.created_at', 'h.monthly_salary', 'h.hourly_rate', 'h.effective_from', 'h.note', 'b.name as by'])
            ->each(fn ($x) => $feed->push(['at' => $x->created_at, 'type' => 'salary', 'text' => 'Salary changed from ' . substr((string) $x->effective_from, 0, 7) . ($x->monthly_salary !== null ? ' → ₹' . number_format((float) $x->monthly_salary) : ''), 'detail' => trim(($x->by ? "by {$x->by}" : '') . ($x->note ? " — {$x->note}" : '')), 'lead_id' => null]));

        $devices = DB::table('user_devices')->where('company_id', $cid)->where('user_id', $u->id)->orderByDesc('id')->limit(10)->get(['id', 'device_name', 'platform', 'app_version', 'ip', 'last_active_at', 'revoked_at', 'created_at']);
        $sorted = $feed->filter(fn ($f) => $f['at'])->sortByDesc('at')->take(80)->values();

        return response()->json(['last_login_at' => $u->last_login_at, 'devices' => $devices, 'feed' => $sorted]);
    }

    // ── personal + parents details ─────────────────────────────────────────────
    private const PERSONAL_TEXT = [
        'dob' => ['nullable', 'date'], 'gender' => ['nullable', 'string', 'max:12'], 'blood_group' => ['nullable', 'string', 'max:5'],
        'marital_status' => ['nullable', 'string', 'max:15'], 'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:80'],
        'state' => ['nullable', 'string', 'max:80'], 'pincode' => ['nullable', 'string', 'max:12'],
        'father_name' => ['nullable', 'string', 'max:120'], 'father_phone' => ['nullable', 'string', 'max:20'],
        'mother_name' => ['nullable', 'string', 'max:120'], 'mother_phone' => ['nullable', 'string', 'max:20'],
        'emergency_name' => ['nullable', 'string', 'max:120'], 'emergency_phone' => ['nullable', 'string', 'max:20'],
        'emergency_relation' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:1000'],
    ];

    private function personalRow(int $uid): array
    {
        $row = !\Illuminate\Support\Facades\Schema::hasTable('staff_personal_details') ? [] : (array) (DB::table('staff_personal_details')->where('company_id', $this->cid())->where('user_id', $uid)->first() ?? []);
        $keys = array_merge(array_keys(self::PERSONAL_TEXT), ['alternate_phones', 'other_numbers']);
        $out = [];
        foreach ($keys as $k) {
            $v = $row[$k] ?? null;
            $out[$k] = in_array($k, ['alternate_phones', 'other_numbers'], true) ? (is_string($v) ? (json_decode($v, true) ?: []) : []) : $v;
        }
        return $out;
    }

    public function personal(int $staff): JsonResponse
    {
        return response()->json(['phone' => $this->person($staff)->phone] + $this->personalRow($staff));
    }

    public function updatePersonal(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $rules = self::PERSONAL_TEXT + [
            'phone' => ['nullable', 'string', 'max:20'],
            'alternate_phones' => ['nullable', 'array', 'max:10'], 'alternate_phones.*.label' => ['nullable', 'string', 'max:40'], 'alternate_phones.*.number' => ['required', 'string', 'max:20'],
            'other_numbers' => ['nullable', 'array', 'max:10'], 'other_numbers.*.label' => ['nullable', 'string', 'max:40'], 'other_numbers.*.number' => ['required', 'string', 'max:20'],
        ];
        $d = $r->validate($rules);
        if (array_key_exists('phone', $d)) { $u->update(['phone' => $d['phone']]); }   // the phone lives on the user, so it is saved either way
        unset($d['phone']);
        if (!\Illuminate\Support\Facades\Schema::hasTable('staff_personal_details')) {
            return response()->json(['message' => 'Phone saved, but the other personal details cannot be stored yet — the server database update (staff_personal_details migration) has not been run.'], 503);
        }
        foreach (['alternate_phones', 'other_numbers'] as $k) {
            $d[$k] = json_encode(array_values(array_filter((array) ($d[$k] ?? []), fn ($x) => trim((string) ($x['number'] ?? '')) !== '')));
        }
        $d += ['updated_at' => now()];
        $exists = DB::table('staff_personal_details')->where('user_id', $u->id)->exists();
        if ($exists) { DB::table('staff_personal_details')->where('user_id', $u->id)->update($d); }
        else { DB::table('staff_personal_details')->insert($d + ['company_id' => $this->cid(), 'user_id' => $u->id, 'created_at' => now()]); }

        return $this->personal($staff);
    }

    /**
     * GET /staff/{staff}/overview?month=YYYY-MM — everything about one person in one call: profile, personal details, this month's attendance,
     * leaves, payroll history, sales targets/progress and tasks.
     */
    public function overview(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff)->load('role:id,name,label');
        $cid = $this->cid();
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('month')) ? (string) $r->query('month') : now()->format('Y-m');
        $from = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $to = $from->copy()->endOfMonth();

        $prof = DB::table('hr_staff_profiles')->where('user_id', $u->id)->first();
        $att = DB::table('hr_attendance')->where('company_id', $cid)->where('user_id', $u->id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->orderBy('work_date')->get();
        $leaves = DB::table('hr_leave_requests as l')->leftJoin('hr_leave_types as t', 't.id', '=', 'l.leave_type_id')
            ->where('l.company_id', $cid)->where('l.user_id', $u->id)->orderByDesc('l.start_date')->limit(20)
            ->get(['l.id', 'l.start_date', 'l.end_date', 'l.days', 'l.half_day', 'l.status', 'l.reason', 't.name as type']);
        $payroll = DB::table('hr_payroll_items as i')->join('hr_payroll_runs as p', 'p.id', '=', 'i.payroll_run_id')
            ->where('i.company_id', $cid)->where('i.user_id', $u->id)->orderByDesc('p.period')->limit(12)
            ->get(['p.period', 'p.status as run_status', 'i.present_days', 'i.paid_leave_days', 'i.unpaid_leave_days', 'i.absent_days', 'i.late_days', 'i.gross_pay', 'i.deductions', 'i.net_pay', 'i.status']);
        $tasks = DB::table('crm_tasks')->where('company_id', $cid)->where('assigned_to', $u->id);
        $taskStats = [
            'open' => (clone $tasks)->where('status', 'open')->count(),
            'overdue' => (clone $tasks)->where('status', 'open')->where('due_at', '<', now())->count(),
            'done_total' => (clone $tasks)->where('status', 'done')->count(),
            'done_this_month' => (clone $tasks)->where('status', 'done')->whereBetween('completed_at', [$from, $to])->count(),
        ];
        $recentTasks = (clone $tasks)->orderByRaw("status = 'open' desc")->orderByDesc('updated_at')->limit(10)->get(['id', 'title', 'type', 'priority', 'status', 'due_at', 'completed_at']);

        $sales = null;
        try { $sales = collect(app(\App\Modules\Hr\Support\SalesActivityMetrics::class)->team($cid, now(), [$u->id])['staff'])->first(); } catch (\Throwable) {}
        $leads = DB::table('leads')->where('company_id', $cid)->where('assigned_to', $u->id)->whereNull('deleted_at');

        $count = fn (string $s) => $att->where('status', $s)->count();
        return response()->json([
            'month' => $month,
            'profile' => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'avatar' => $u->avatar, 'is_active' => (bool) $u->is_active,
                'role' => $u->role?->label ?? $u->role?->name, 'department' => $u->department, 'designation' => $u->designation, 'joined_on' => $u->joined_on?->toDateString(),
                'last_login_at' => $u->last_login_at, 'max_leads' => $u->max_leads,
                'work' => $prof ? ['shift' => $prof->shift_id ? DB::table('hr_shifts')->where('id', $prof->shift_id)->value('name') : null, 'attendance_type' => $prof->attendance_type, 'work_mode' => $prof->work_mode, 'duty_start' => $prof->duty_start, 'duty_end' => $prof->duty_end,
                    'weekly_off' => json_decode((string) $prof->weekly_off, true) ?: [], 'monthly_salary' => (float) $prof->monthly_salary, 'hourly_rate' => $prof->hourly_rate !== null ? (float) $prof->hourly_rate : null, 'monthly_target' => $this->comp()->monthlyTarget($cid, $u->id, $month)] : null,
            ],
            'personal' => $this->personalRow($u->id),
            'attendance' => [
                'summary' => ['present' => $count('present'), 'absent' => $count('absent'), 'on_leave' => $count('on_leave'), 'half_day' => $count('half_day'),
                    'late_days' => $att->where('late_minutes', '>', 0)->count(), 'late_minutes' => (int) $att->sum('late_minutes'),
                    'worked_hours' => round($att->sum('worked_minutes') / 60, 1), 'overtime_hours' => round($att->sum('overtime_minutes') / 60, 1)],
                'days' => $att->map(fn ($a) => ['date' => $a->work_date, 'status' => $a->status, 'in' => $a->clock_in_at, 'out' => $a->clock_out_at,
                    'late_minutes' => (int) $a->late_minutes, 'worked_minutes' => (int) $a->worked_minutes])->values(),
            ],
            'leaves' => $leaves,
            'leave_balance' => DB::table('hr_leave_types as t')->where('t.company_id', $cid)->where('t.is_active', true)->orderBy('t.sort_order')->get(['t.id', 't.name', 't.max_days_per_year', 't.is_paid'])
                ->map(function ($t) use ($cid, $u) {
                    $used = (float) DB::table('hr_leave_requests')->where('company_id', $cid)->where('user_id', $u->id)->where('leave_type_id', $t->id)->where('status', 'approved')
                        ->whereYear('start_date', now()->year)->sum('days');
                    return ['name' => $t->name, 'paid' => (bool) $t->is_paid, 'allowed' => $t->max_days_per_year, 'used' => $used, 'left' => $t->max_days_per_year !== null ? max(0, $t->max_days_per_year - $used) : null];
                })->values(),
            'payroll' => $payroll,
            'sales' => $sales,
            'leads' => ['assigned' => (clone $leads)->count(), 'enrolled' => (clone $leads)->whereNotNull('enrolled_at')->count()],
            'tasks' => ['stats' => $taskStats, 'recent' => $recentTasks],
        ]);
    }

    /**
     * POST /staff/{staff}/login-as — the owner (or anyone granted `staff.login_as`) signs in as one of their staff (to see exactly what that person
     * sees, or help them). Returns a token for that user; the web keeps the owner's own token to switch back.
     */
    public function loginAs(Request $request, int $staff): JsonResponse
    {
        $me = auth()->user();
        if (!$me->isOwner() && !$me->hasPermission('staff.login_as')) return response()->json(['message' => 'You do not have permission to sign in as staff.'], 403);

        $target = User::where('company_id', $me->company_id)->with('role')->findOrFail($staff);
        if ($target->id === $me->id) return response()->json(['message' => 'That is your own account.'], 422);
        if ($target->isOwner())      return response()->json(['message' => 'You cannot sign in as another owner.'], 422);
        if (!$target->is_active)     return response()->json(['message' => 'Activate this staff member first.'], 422);
        // no climbing the ladder: someone who can sign in as staff can't sign in as another person who can
        if (!$me->isOwner() && $target->hasPermission('staff.login_as')) return response()->json(['message' => 'You cannot sign in as this person.'], 403);

        Log::info('staff.login_as', ['by' => $me->id, 'as' => $target->id, 'company' => $me->company_id]);
        return response()->json([
            'message'      => "Signed in as {$target->name}.",
            'access_token' => JWTAuth::fromUser($target),
            'name'         => $target->name,
        ]);
    }
}
