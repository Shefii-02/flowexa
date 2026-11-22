<?php

namespace App\Services;

use App\Jobs\AppendLeadToSheet;
use App\Models\Company;
use App\Models\GoogleIntegration;
use App\Models\Lead;
use App\Modules\Google\GoogleClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Also save every new lead to a Google Sheet" (Assignment Rules → Lead sheet). The lead is saved to the database exactly as before and ALSO
 * appended as one row to the company's sheet the moment it arrives — queued, so a slow or failing Google never delays or loses a lead.
 * Bulk imports are not appended row by row (thousands of API calls); use the periodic Google Sheets sync for those.
 */
class LeadSheet
{
    public const HEADER = ['ID', 'Created', 'Name', 'Phone', 'Email', 'Stage', 'Source', 'Category', 'Priority', 'Assigned to', 'Notes'];

    public function __construct(private readonly GoogleClient $google) {}

    /** The plan must include Google Sheets AND a Google account must be connected. */
    public function options(?Company $company): array
    {
        $inPlan = !$company?->plan || (bool) $company->plan->google_sheets_enabled;
        $integration = $company ? GoogleIntegration::where('company_id', $company->id)->where('is_active', true)->first() : null;
        $reason = !$inPlan ? 'Google Sheets is not included in your plan.'
            : (!$integration ? 'Connect a Google account first (Settings → Integrations → Google).' : null);

        return ['available' => $reason === null, 'in_plan' => $inPlan, 'connected' => (bool) $integration, 'email' => $integration?->google_email, 'reason' => $reason];
    }

    public function settings(int $companyId): object
    {
        return DB::table('lead_sheet_settings')->where('company_id', $companyId)->first()
            ?? (object) ['is_enabled' => false, 'spreadsheet_id' => null, 'sheet_url' => null];
    }

    /** Called for every new lead (model event). Cheap no-op unless the company switched the sheet on. */
    public function record(Lead $lead): void
    {
        try {
            if ($lead->source === 'import') { return; }
            $row = DB::table('lead_sheet_settings')->where('company_id', $lead->company_id)->where('is_enabled', true)->exists();
            if (!$row) { return; }
            // a short delay lets auto-assignment and categorisation (which run right after a lead is created) land first, so the row is complete
            AppendLeadToSheet::dispatch((int) $lead->company_id, (int) $lead->id)->delay(now()->addSeconds(20));
        } catch (\Throwable $e) {
            Log::warning('Lead sheet: could not queue a row — ' . $e->getMessage());   // never fail lead creation over the sheet
        }
    }

    public function ensureSheet(int $companyId, GoogleIntegration $integration): string
    {
        $s = $this->settings($companyId);
        return $s->spreadsheet_id ?: $this->createSheet($companyId, $integration);
    }

    private function createSheet(int $companyId, GoogleIntegration $integration): string
    {
        $name  = Company::where('id', $companyId)->value('name') ?: config('app.name');
        $sheet = $this->google->createSpreadsheet($integration, "{$name} — Leads", null, self::HEADER);
        DB::table('lead_sheet_settings')->updateOrInsert(['company_id' => $companyId], ['spreadsheet_id' => $sheet['id'], 'sheet_url' => $sheet['url'], 'updated_at' => now(), 'created_at' => now()]);
        return $sheet['id'];
    }

    /** Append one lead (queued job). A sheet deleted on Google's side is replaced once. */
    public function append(int $companyId, int $leadId): void
    {
        if (!$this->settings($companyId)->is_enabled) { return; }
        $lead = Lead::with(['contact:id,name,phone,email', 'assignedTo:id,name'])->where('company_id', $companyId)->find($leadId);
        if (!$lead) { return; }
        $integration = GoogleIntegration::where('company_id', $companyId)->where('is_active', true)->first();
        if (!$integration) { throw new \RuntimeException("company {$companyId} has no active Google connection"); }

        $row = [
            $lead->id, optional($lead->created_at)->toDateTimeString(), $lead->contact?->name, $lead->contact?->phone, $lead->contact?->email,
            $lead->stage, $lead->source, $lead->category, $lead->priority, $lead->assignedTo?->name, mb_substr((string) $lead->notes, 0, 2000),
        ];
        $id = $this->ensureSheet($companyId, $integration);
        try {
            $this->google->appendRows($integration, $id, [$row]);
        } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'not found')) { throw $e; }
            Log::info("Lead sheet: the saved sheet for company {$companyId} no longer exists — creating a new one");
            $this->google->appendRows($integration, $this->createSheet($companyId, $integration), [$row]);
        }
    }
}
