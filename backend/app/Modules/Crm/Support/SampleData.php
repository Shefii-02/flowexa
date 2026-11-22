<?php

namespace App\Modules\Crm\Support;

use App\Models\CrmDeal;
use App\Models\CrmSegment;

/**
 * "Add sample data" for the Deals and Segments pages — a handful of realistic examples so a new company can see how the pipeline and
 * smart lists look before entering real records. Every sample row is tagged (deals: source = "sample"; segments: description starts with
 * "Sample —") so "Remove sample data" deletes exactly those and never a real record. Adding twice never duplicates.
 */
class SampleData
{
    public const DEAL_SOURCE = 'sample';
    public const SEGMENT_PREFIX = 'Sample — ';

    /** @return array<int, array{title:string, value:int, stage:string, close:int, notes:string, lost?:string}> close = days from today */
    private static function deals(): array
    {
        return [
            ['title' => 'Website redesign — Bright Dental Clinic', 'value' => 85000,  'stage' => 'new',         'close' => 30, 'notes' => 'Asked for a quote after seeing our portfolio.'],
            ['title' => 'Monthly WhatsApp campaigns — Urban Cafe', 'value' => 24000,  'stage' => 'new',         'close' => 21, 'notes' => 'Wants festival offers sent to 3,000 customers.'],
            ['title' => 'CRM setup for 12 agents — Skyline Realty', 'value' => 150000, 'stage' => 'qualified',   'close' => 25, 'notes' => 'Budget confirmed, decision maker identified.'],
            ['title' => 'Admission enquiry bot — Greenfield School', 'value' => 60000,  'stage' => 'proposal',    'close' => 14, 'notes' => 'Proposal sent, waiting for the principal.'],
            ['title' => 'Annual plan upgrade — FitZone Gym',        'value' => 42000,  'stage' => 'negotiation', 'close' => 7,  'notes' => 'Asking for a 10% discount on the yearly price.'],
            ['title' => 'Lead capture widget — Sharma Interiors',   'value' => 18000,  'stage' => 'won',         'close' => -3, 'notes' => 'Signed. Onboarding call booked.'],
            ['title' => 'Support desk on WhatsApp — Metro Pharmacy', 'value' => 72000,  'stage' => 'won',         'close' => -10, 'notes' => 'Paid in full.'],
            ['title' => 'Custom report pack — Orbit Travels',       'value' => 30000,  'stage' => 'lost',        'close' => -5, 'notes' => 'Went with a cheaper tool.', 'lost' => 'Price'],
        ];
    }

    /** @return array<int, array{name:string, description:string, filters:array<string,mixed>}> */
    private static function segments(): array
    {
        return [
            ['name' => 'Hot leads (score 70+)',      'description' => 'Contacts with a lead score of 70 or more',            'filters' => ['score_min' => 70]],
            ['name' => 'New leads',                  'description' => 'Contacts still in the "new" stage',                   'filters' => ['lead_stage' => 'new']],
            ['name' => 'Interested this week',       'description' => 'Interested contacts who messaged in the last 7 days', 'filters' => ['lead_stage' => 'interested', 'last_message_days' => 7]],
            ['name' => 'Opted-in contacts',          'description' => 'Contacts who agreed to receive messages',             'filters' => ['opted_in' => true]],
            ['name' => 'Contacts with open leads',   'description' => 'Anyone who already has at least one lead',            'filters' => ['has_leads' => true]],
        ];
    }

    /** @return int how many deals were added (0 = the samples were already there) */
    public static function addDeals(int $companyId): int
    {
        if (CrmDeal::where('company_id', $companyId)->where('source', self::DEAL_SOURCE)->exists()) { return 0; }

        foreach (self::deals() as $i => $d) {
            $status = CrmDeal::statusForStage($d['stage']);
            // created directly (not through the form endpoint): no owner and no incentive rule, so a sample "won" deal can never award an HR incentive
            CrmDeal::create([
                'company_id' => $companyId, 'title' => $d['title'], 'value' => $d['value'], 'currency' => 'INR',
                'stage' => $d['stage'], 'status' => $status, 'expected_close_date' => now()->addDays($d['close'])->toDateString(),
                'source' => self::DEAL_SOURCE, 'notes' => $d['notes'] . ' (sample deal — edit or delete freely)', 'lost_reason' => $d['lost'] ?? null,
                'sort_order' => $i, 'closed_at' => in_array($status, ['won', 'lost'], true) ? now()->subDays(abs(min(0, $d['close']))) : null,
            ]);
        }
        return count(self::deals());
    }

    /** @return int how many sample deals were deleted */
    public static function removeDeals(int $companyId): int
    {
        return CrmDeal::where('company_id', $companyId)->where('source', self::DEAL_SOURCE)->delete();
    }

    /** @return int how many segments were added (a name that already exists is left alone) */
    public static function addSegments(int $companyId, ?int $userId): int
    {
        $added = 0;
        foreach (self::segments() as $s) {
            $name = self::SEGMENT_PREFIX . $s['name'];
            if (CrmSegment::where('company_id', $companyId)->where('name', $name)->exists()) { continue; }
            CrmSegment::create(['company_id' => $companyId, 'created_by' => $userId, 'name' => $name, 'description' => $s['description'], 'filters' => $s['filters'], 'is_shared' => true]);
            $added++;
        }
        return $added;
    }

    /** @return int how many sample segments were deleted */
    public static function removeSegments(int $companyId): int
    {
        return CrmSegment::where('company_id', $companyId)->where('name', 'like', self::SEGMENT_PREFIX . '%')->delete();
    }
}
