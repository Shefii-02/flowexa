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
        $row = (array) (DB::table('staff_personal_details')->where('company_id', $this->cid())->where('user_id', $uid)->first() ?? []);
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
        if (array_key_exists('phone', $d)) { $u->update(['phone' => $d['phone']]); }
        unset($d['phone']);
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
                'work' => $prof ? ['attendance_type' => $prof->attendance_type, 'work_mode' => $prof->work_mode, 'duty_start' => $prof->duty_start, 'duty_end' => $prof->duty_end,
                    'weekly_off' => json_decode((string) $prof->weekly_off, true) ?: [], 'monthly_salary' => (float) $prof->monthly_salary, 'monthly_target' => (float) $prof->monthly_target] : null,
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
