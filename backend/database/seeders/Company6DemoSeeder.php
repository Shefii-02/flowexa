<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CrmDeal;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\ListingSale;
use App\Models\User;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrBreakSession;
use App\Modules\Hr\Models\HrBreakType;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrLeaveType;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Models\HrSetting;
use App\Modules\Hr\Models\HrStaffProfile;
use App\Modules\Hr\Support\SalesService;
use Illuminate\Database\Seeder;

/**
 * Demo/test data for ONE specific company (id 6) — enough across CRM, HR and Leads that
 * the mobile app's Home/Leads/Target/Attendance/Payroll screens (and the equivalent web
 * pages) all have something real to render instead of empty states.
 *
 * Staff ids are never hardcoded: every record is assigned to a user pulled live from
 * `users` where company_id = 6, so this stays correct regardless of which staff currently
 * exist on that company, and spreads data across all of them rather than favouring one.
 *
 * Safe to re-run — each domain guards against piling up duplicates on top of what it
 * already seeded (skips once a "there's already enough of this" threshold is met).
 *
 *   php artisan db:seed --class="Database\Seeders\Company6DemoSeeder"
 */
class Company6DemoSeeder extends Seeder
{
    private const COMPANY_ID = 6;

    private const FIRST_NAMES = ['Rahul', 'Sneha', 'Arjun', 'Neha', 'Pooja', 'Vikram', 'Priya', 'Karan', 'Ananya', 'Rohit', 'Kavya', 'Aditya', 'Meera', 'Siddharth', 'Divya'];
    private const LAST_NAMES = ['Anand', 'Kulkarni', 'Mehta', 'Iyer', 'Nair', 'Rao', 'Verma', 'Shah', 'Reddy', 'Gupta', 'Menon', 'Kapoor', 'Joshi', 'Malhotra', 'Pillai'];

    /** @var array<int> */
    private array $breakTypeIds = [];
    /** @var array<int> */
    private array $leaveTypeIds = [];

    public function run(): void
    {
        $company = Company::find(self::COMPANY_ID);
        if (!$company) {
            $this->command?->error('Company id ' . self::COMPANY_ID . ' does not exist — aborting.');
            return;
        }

        $staffIds = User::where('company_id', self::COMPANY_ID)->pluck('id')->all();
        if (empty($staffIds)) {
            $this->command?->error('No users found for company_id ' . self::COMPANY_ID . ' — create staff first, then re-run.');
            return;
        }

        $this->command?->info('Seeding demo data for company ' . self::COMPANY_ID . ' (' . count($staffIds) . ' staff)…');

        $this->seedHrSetup($staffIds);
        $contactIds = $this->seedContacts();
        $this->seedLeads($contactIds, $staffIds);
        $this->seedDeals($contactIds, $staffIds);
        $this->seedTasksAndFollowUps($contactIds, $staffIds);
        $this->seedAttendance($staffIds);
        $this->seedLeaveRequests($staffIds);
        $this->seedSales($staffIds);
        $this->seedPayroll($staffIds);

        $this->command?->info('Done.');
    }

    private function randomName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    // ── HR setup: settings, break/leave types, staff profiles + targets ──────
    private function seedHrSetup(array $staffIds): void
    {
        HrSetting::forCompany(self::COMPANY_ID)->update([
            'selfie_required_clock_in' => false,
            'selfie_required_clock_out' => false,
            'selfie_required_break_start' => false,
            'selfie_required_break_end' => false,
            'geofence_mandatory' => false,
        ]);

        $breakTypes = [
            ['name' => 'Lunch', 'max_minutes' => 45, 'daily_limit' => 1],
            ['name' => 'Tea Break', 'max_minutes' => 15, 'daily_limit' => 2],
        ];
        foreach ($breakTypes as $i => $bt) {
            HrBreakType::firstOrCreate(
                ['company_id' => self::COMPANY_ID, 'name' => $bt['name']],
                $bt + ['company_id' => self::COMPANY_ID, 'is_paid' => true, 'requires_gps' => false, 'sort_order' => $i, 'is_active' => true],
            );
        }
        $this->breakTypeIds = HrBreakType::where('company_id', self::COMPANY_ID)->pluck('id')->all();

        $leaveTypes = [
            ['name' => 'Casual Leave', 'max_days_per_year' => 12],
            ['name' => 'Sick Leave', 'max_days_per_year' => 10],
        ];
        foreach ($leaveTypes as $i => $lt) {
            HrLeaveType::firstOrCreate(
                ['company_id' => self::COMPANY_ID, 'name' => $lt['name']],
                $lt + ['company_id' => self::COMPANY_ID, 'is_paid' => true, 'requires_approval' => true, 'color' => 'info', 'sort_order' => $i, 'is_active' => true],
            );
        }
        $this->leaveTypeIds = HrLeaveType::where('company_id', self::COMPANY_ID)->pluck('id')->all();

        foreach ($staffIds as $uid) {
            HrStaffProfile::forUser(self::COMPANY_ID, $uid)->update([
                'attendance_type' => 'manual',
                'work_mode' => 'wfo',
                'monthly_target' => rand(8, 25) * 50000,
                'is_active' => true,
            ]);
        }
    }

    // ── Contacts ──────────────────────────────────────────────────────────
    /** @return array<int> contact ids for this company */
    private function seedContacts(): array
    {
        $existing = Contact::where('company_id', self::COMPANY_ID)->pluck('id')->all();
        if (count($existing) >= 15) {
            return $existing;
        }

        $created = [];
        for ($i = 0; $i < 18; $i++) {
            $name = $this->randomName();
            $contact = Contact::create([
                'company_id' => self::COMPANY_ID,
                'name' => $name,
                'phone' => '9' . str_pad((string) rand(0, 999999999), 9, '0', STR_PAD_LEFT),
                'email' => strtolower(str_replace(' ', '.', $name)) . rand(1, 999) . '@example.com',
                'opted_in' => true,
                'lead_score' => rand(20, 95),
            ]);
            $created[] = $contact->id;
        }

        return array_merge($existing, $created);
    }

    // ── Leads (sales pipeline: New / Contacted+Follow-up / Won / Lost) ──────
    private function seedLeads(array $contactIds, array $staffIds): void
    {
        if (Lead::where('company_id', self::COMPANY_ID)->count() >= 15) {
            return;
        }

        $stages = ['new', 'new', 'new', 'contacted', 'contacted', 'follow_up', 'follow_up', 'enrolled', 'enrolled', 'lost'];
        $sources = ['manual', 'flow', 'campaign', 'api'];
        $categories = ['2BHK', '3BHK', 'Villa', 'Plot', 'Commercial'];
        $priorities = ['low', 'medium', 'high'];

        foreach (array_slice($contactIds, 0, 20) as $contactId) {
            $stage = $stages[array_rand($stages)];
            $daysAgo = $stage === 'new' ? rand(0, 1) : rand(1, 20);
            $assignedTo = $staffIds[array_rand($staffIds)];

            $lead = Lead::create([
                'company_id' => self::COMPANY_ID,
                'contact_id' => $contactId,
                'assigned_to' => $assignedTo,
                'stage' => $stage,
                'priority' => $priorities[array_rand($priorities)],
                'category' => $categories[array_rand($categories)],
                'source' => $sources[array_rand($sources)],
                'followed_up_at' => in_array($stage, ['contacted', 'follow_up', 'enrolled'], true) ? now()->subDays(rand(0, 5)) : null,
                'enrolled_at' => $stage === 'enrolled' ? now()->subDays(rand(0, 10)) : null,
                'assigned_at' => now()->subDays($daysAgo),
            ]);

            if ($daysAgo > 0) {
                Lead::where('id', $lead->id)->update(['created_at' => now()->subDays($daysAgo)]);
            }
        }
    }

    // ── CRM deals (Kanban stages) ────────────────────────────────────────────
    private function seedDeals(array $contactIds, array $staffIds): void
    {
        if (CrmDeal::where('company_id', self::COMPANY_ID)->count() >= 10) {
            return;
        }

        foreach (range(1, 12) as $i) {
            $stage = CrmDeal::STAGES[array_rand(CrmDeal::STAGES)];
            CrmDeal::create([
                'company_id' => self::COMPANY_ID,
                'contact_id' => $contactIds[array_rand($contactIds)],
                'owner_id' => $staffIds[array_rand($staffIds)],
                'title' => 'Deal #' . (1000 + $i),
                'value' => rand(50, 800) * 1000,
                'currency' => 'INR',
                'stage' => $stage,
                'status' => CrmDeal::statusForStage($stage),
                'closed_at' => in_array($stage, ['won', 'lost'], true) ? now()->subDays(rand(1, 15)) : null,
            ]);
        }
    }

    // ── CRM tasks: today's checklist (todo/meeting) + follow-ups (call/whatsapp/email) ──
    private function seedTasksAndFollowUps(array $contactIds, array $staffIds): void
    {
        if (CrmTask::where('company_id', self::COMPANY_ID)->count() >= 20) {
            return;
        }

        $todoTitles = ['Send proposal', 'Update CRM notes', 'Team standup', 'Prepare weekly report', 'Site visit follow-up'];
        $priorities = ['low', 'medium', 'high'];

        foreach ($staffIds as $uid) {
            // Today's tasks — 5 items, 3 already done (matches the reference design's "3 of 5 done").
            foreach (range(1, 5) as $i) {
                $done = $i <= 3;
                CrmTask::create([
                    'company_id' => self::COMPANY_ID,
                    'assigned_to' => $uid,
                    'created_by' => $uid,
                    'title' => $todoTitles[array_rand($todoTitles)],
                    'type' => $i === 5 ? 'meeting' : 'todo',
                    'priority' => $priorities[array_rand($priorities)],
                    'status' => $done ? 'done' : 'open',
                    'due_at' => today()->addHours(rand(9, 18)),
                    'completed_at' => $done ? now() : null,
                ]);
            }

            // Follow-ups — one per bucket (missed / late / due today) so the Home
            // dashboard's Follow-ups section has a real example of each.
            $buckets = [
                ['type' => 'call', 'due_at' => now()->subHours(2)],
                ['type' => 'whatsapp', 'due_at' => now()->subDay()],
                ['type' => 'call', 'due_at' => now()->addHours(3)],
            ];
            foreach ($buckets as $b) {
                CrmTask::create([
                    'company_id' => self::COMPANY_ID,
                    'assigned_to' => $uid,
                    'created_by' => $uid,
                    'contact_id' => $contactIds[array_rand($contactIds)],
                    'title' => ucfirst($b['type']) . ' follow-up',
                    'type' => $b['type'],
                    'priority' => 'medium',
                    'status' => 'open',
                    'due_at' => $b['due_at'],
                ]);
            }
        }
    }

    // ── Attendance: ~18 recent working days + today always clocked in ──────
    private function seedAttendance(array $staffIds): void
    {
        $breakTypeId = $this->breakTypeIds[0] ?? null;

        foreach ($staffIds as $uid) {
            $cursor = today()->subDays(25);
            $created = 0;
            while ($created < 18 && $cursor->lt(today())) {
                $cursor = $cursor->copy()->addDay();
                if ($cursor->isWeekend()) {
                    continue;
                }
                $created++;

                $roll = rand(1, 100);
                if ($roll <= 5) {
                    HrAttendance::updateOrCreate(
                        ['company_id' => self::COMPANY_ID, 'user_id' => $uid, 'work_date' => $cursor->toDateString()],
                        ['status' => 'absent'],
                    );
                    continue;
                }
                if ($roll <= 10) {
                    HrAttendance::updateOrCreate(
                        ['company_id' => self::COMPANY_ID, 'user_id' => $uid, 'work_date' => $cursor->toDateString()],
                        ['status' => 'on_leave'],
                    );
                    continue;
                }

                $late = $roll <= 30;
                $clockIn = $cursor->copy()->setTime(9, $late ? rand(20, 50) : rand(0, 10));
                $clockOut = $cursor->copy()->setTime(18, rand(0, 30));
                $worked = max(0, $clockIn->diffInMinutes($clockOut) - 30);

                HrAttendance::updateOrCreate(
                    ['company_id' => self::COMPANY_ID, 'user_id' => $uid, 'work_date' => $cursor->toDateString()],
                    [
                        'clock_in_at' => $clockIn, 'clock_out_at' => $clockOut,
                        'clock_in_status' => $late ? 'warning' : 'success',
                        'work_mode' => 'wfo', 'source' => 'mobile',
                        'late_minutes' => $late ? (int) $cursor->copy()->setTime(9, 0)->diffInMinutes($clockIn) : 0,
                        'break_minutes' => 30, 'worked_minutes' => $worked,
                        'status' => 'present',
                        'note_color' => $late ? 'warning' : 'success',
                        'note_message' => $late ? 'Clocked in late.' : 'On time.',
                    ],
                );
            }

            // Today — always clocked in (not out yet), so whichever staff member logs in
            // sees a live "Clocked in" state on Home rather than "Not clocked in".
            $todayClockIn = today()->setTime(9, rand(5, 25));
            $att = HrAttendance::updateOrCreate(
                ['company_id' => self::COMPANY_ID, 'user_id' => $uid, 'work_date' => today()->toDateString()],
                [
                    'clock_in_at' => $todayClockIn, 'work_mode' => 'wfo', 'source' => 'mobile',
                    'clock_in_status' => 'success', 'late_minutes' => 0,
                    'status' => 'present', 'note_color' => 'success', 'note_message' => 'On time.',
                ],
            );

            // A completed lunch break earlier today, so Break in/out aren't blank.
            if ($breakTypeId && !$att->breaks()->exists()) {
                HrBreakSession::create([
                    'company_id' => self::COMPANY_ID, 'user_id' => $uid, 'attendance_id' => $att->id,
                    'break_type_id' => $breakTypeId,
                    'start_at' => today()->setTime(13, 2), 'end_at' => today()->setTime(13, 32),
                    'minutes' => 30, 'over_limit' => false, 'start_status' => 'info',
                ]);
                $att->update(['break_minutes' => 30]);
            }
        }
    }

    // ── Leave requests ────────────────────────────────────────────────────
    private function seedLeaveRequests(array $staffIds): void
    {
        if (HrLeaveRequest::where('company_id', self::COMPANY_ID)->count() > 0) {
            return;
        }
        $leaveTypeId = $this->leaveTypeIds[0] ?? null;
        if (!$leaveTypeId) {
            return;
        }

        foreach ($staffIds as $uid) {
            HrLeaveRequest::create([
                'company_id' => self::COMPANY_ID, 'user_id' => $uid, 'leave_type_id' => $leaveTypeId,
                'start_date' => today()->addDays(rand(5, 15)), 'end_date' => today()->addDays(rand(16, 18)),
                'days' => 2, 'half_day' => false, 'reason' => 'Personal work', 'status' => 'pending',
            ]);
        }
    }

    // ── Sales (target progress + leaderboard) ────────────────────────────────
    private function seedSales(array $staffIds): void
    {
        $listing = Listing::firstOrCreate(
            ['company_id' => self::COMPANY_ID, 'title' => 'Premium 2BHK Apartment'],
            ['type' => 'property', 'status' => 'active', 'price' => 4500000, 'currency' => 'INR', 'source' => 'manual'],
        );

        $salesService = app(SalesService::class);

        foreach ($staffIds as $uid) {
            $alreadySold = ListingSale::where('company_id', self::COMPANY_ID)->where('staff_id', $uid)
                ->whereYear('sold_at', now()->year)->whereMonth('sold_at', now()->month)->exists();
            if ($alreadySold) {
                continue;
            }

            foreach (range(1, rand(3, 7)) as $i) {
                // `created_by` is passed explicitly — SalesService::record() defaults it to
                // auth()->id(), which is null from a console/seeder context.
                $salesService->record(self::COMPANY_ID, [
                    'listing_id' => $listing->id,
                    'staff_id' => $uid,
                    'amount' => rand(30, 200) * 1000,
                    'currency' => 'INR',
                    'sold_at' => now()->subDays(rand(0, 20))->toDateString(),
                    'note' => 'Demo sale',
                    'created_by' => $uid,
                ]);
            }
        }
    }

    // ── Payroll: two released runs so the self-service Payroll screen has data ──
    private function seedPayroll(array $staffIds): void
    {
        foreach ([1, 2] as $monthsAgo) {
            $period = now()->subMonthsNoOverflow($monthsAgo)->format('Y-m');
            $run = HrPayrollRun::firstOrCreate(
                ['company_id' => self::COMPANY_ID, 'period' => $period],
                ['status' => 'released', 'released_at' => now()->subMonthsNoOverflow($monthsAgo)->endOfMonth()],
            );
            if ($run->status !== 'released') {
                $run->update(['status' => 'released', 'released_at' => now()->subMonthsNoOverflow($monthsAgo)->endOfMonth()]);
            }

            foreach ($staffIds as $uid) {
                if (HrPayrollItem::where('payroll_run_id', $run->id)->where('user_id', $uid)->exists()) {
                    continue;
                }

                $base = rand(25, 60) * 1000;
                $item = new HrPayrollItem([
                    'payroll_run_id' => $run->id, 'company_id' => self::COMPANY_ID, 'user_id' => $uid,
                    'present_days' => rand(20, 26), 'paid_leave_days' => rand(0, 2), 'unpaid_leave_days' => 0,
                    'absent_days' => rand(0, 1), 'late_days' => rand(0, 3),
                    'worked_hours' => rand(160, 200), 'overtime_hours' => rand(0, 10),
                    'base_pay' => $base, 'overtime_pay' => rand(0, 3) * 500,
                    'incentive_pay' => rand(2, 15) * 1000,
                    'allowances' => round($base * 0.2), 'deductions' => round($base * 0.08),
                    'status' => 'approved',
                ]);
                $item->recompute();
                $item->save();
            }
        }
    }
}
