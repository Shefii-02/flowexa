<?php

namespace App\Modules\Hr\Support;

use App\Jobs\AppendAttendanceToSheet;
use App\Models\Company;
use App\Models\GoogleIntegration;
use App\Models\User;
use App\Modules\Google\GoogleClient;
use App\Modules\Hr\Models\HrSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * "Also keep attendance in a Google Sheet" (HR → Office Details → Storage). Every punch — clock in, clock out, break start, break end —
 * becomes ONE row appended to the company's attendance sheet: an event log, because a day's record changes after clock-in and a sheet
 * can only be appended to safely. The append runs on the queue, so a slow or failing Google never delays or blocks a punch.
 */
class AttendanceSheet
{
    public const HEADER = ['Date', 'Time', 'Staff', 'Event', 'Timing', 'Reason / note', 'Work mode', 'Source', 'Distance from office (m)', 'Outside radius', 'Radius reason', 'Break type', 'Photo'];

    public function __construct(private readonly GoogleClient $google) {}

    /** What the setting can use for this company: the plan must include Google Sheets AND a Google account must be connected. */
    public function options(?Company $company): array
    {
        $inPlan = !$company?->plan || (bool) $company->plan->google_sheets_enabled;
        $integration = $company ? GoogleIntegration::where('company_id', $company->id)->where('is_active', true)->first() : null;
        $reason = !$inPlan
            ? 'Google Sheets is not included in your plan.'
            : (!$integration ? 'Connect a Google account first (Settings → Integrations → Google).' : null);

        return ['available' => $reason === null, 'in_plan' => $inPlan, 'connected' => (bool) $integration, 'email' => $integration?->google_email, 'reason' => $reason];
    }

    /**
     * Queue one row for a punch (no-op when the sheet is switched off).
     *
     * @param string $event  Clock in | Clock out | Break start | Break end
     * @param array{at?:mixed, timing?:string, note?:?string, work_mode?:?string, source?:?string, distance?:?int, outside?:?bool, radius_reason?:?string, break_type?:?string, photo?:?string} $d
     */
    public function record(User $user, string $event, array $d = []): void
    {
        try {
            $settings = HrSetting::forCompany((int) $user->company_id);
            if (!$settings->attendance_sheet_enabled) { return; }

            $at = isset($d['at']) ? Carbon::parse($d['at']) : now();
            AppendAttendanceToSheet::dispatch((int) $user->company_id, [
                $at->toDateString(),
                $at->format('H:i:s'),
                (string) $user->name,
                $event,
                (string) ($d['timing'] ?? ''),
                (string) ($d['note'] ?? ''),
                (string) ($d['work_mode'] ?? ''),
                (string) ($d['source'] ?? ''),
                isset($d['distance']) ? (int) $d['distance'] : '',
                isset($d['outside']) ? ($d['outside'] ? 'Yes' : 'No') : '',
                (string) ($d['radius_reason'] ?? ''),
                (string) ($d['break_type'] ?? ''),
                (string) ($d['photo'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('HR attendance sheet: could not queue a row — ' . $e->getMessage());   // never fail a punch over the sheet
        }
    }

    /** Create the sheet if the company has none yet (also used when switching it on, so the link can be shown at once). */
    public function ensureSheet(HrSetting $settings, GoogleIntegration $integration): string
    {
        if ($settings->attendance_sheet_id) { return $settings->attendance_sheet_id; }
        return $this->createSheet($settings, $integration);
    }

    private function createSheet(HrSetting $settings, GoogleIntegration $integration): string
    {
        $company = Company::find($settings->company_id);
        $sheet = $this->google->createSpreadsheet($integration, ($company?->name ?: config('app.name')) . ' — Attendance', $integration->drive_hr_folder_id ?: null, self::HEADER);
        $settings->forceFill(['attendance_sheet_id' => $sheet['id'], 'attendance_sheet_url' => $sheet['url']])->save();
        return $sheet['id'];
    }

    /** Append a row (called by the queued job). If the sheet was deleted on Google's side, make a new one once and carry on. */
    public function append(int $companyId, array $row): void
    {
        $settings = HrSetting::forCompany($companyId);
        if (!$settings->attendance_sheet_enabled) { return; }
        $integration = GoogleIntegration::where('company_id', $companyId)->where('is_active', true)->first();
        if (!$integration) { throw new \RuntimeException("company {$companyId} has no active Google connection"); }

        $id = $this->ensureSheet($settings, $integration);
        try {
            $this->google->appendRows($integration, $id, [$row]);
        } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'not found')) { throw $e; }
            Log::info("HR attendance sheet: the saved sheet for company {$companyId} no longer exists — creating a new one");
            $this->google->appendRows($integration, $this->createSheet($settings, $integration), [$row]);
        }
    }
}
