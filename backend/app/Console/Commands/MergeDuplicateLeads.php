<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Joins repeated leads of the same person: same company + contact + category, all still open.
 * The oldest open lead is kept; the others' history, tasks, assignments and notes move onto it and the extras are soft-deleted.
 * Won/lost leads are never touched (they are finished opportunities, not repeats).
 */
class MergeDuplicateLeads extends Command
{
    protected $signature = 'leads:merge-duplicates {--company= : only this company id} {--dry-run : show what would be merged, change nothing}';
    protected $description = 'Merge repeated open leads of the same contact + category into the oldest one';

    /** Tables whose lead_id must follow the merged lead. */
    private const LEAD_ID_TABLES = [
        'lead_events', 'lead_assignments', 'crm_tasks', 'calendar_events', 'lead_scores', 'lead_conversion_events',
        'widget_conversations', 'meta_leads', 'instagram_conversations', 'listing_sales', 'flow_node_hits',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $groups = Lead::query()
            ->when($this->option('company'), fn ($q, $c) => $q->where('company_id', (int) $c))
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->whereNotNull('contact_id')
            ->select('company_id', 'contact_id', DB::raw("COALESCE(category, '') as cat"), DB::raw('COUNT(*) as n'))
            ->groupBy('company_id', 'contact_id', DB::raw("COALESCE(category, '')"))
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $merged = 0;
        foreach ($groups as $g) {
            $leads = Lead::where('company_id', $g->company_id)->where('contact_id', $g->contact_id)
                ->whereRaw("COALESCE(category, '') = ?", [$g->cat])
                ->whereNotIn('stage', ['enrolled', 'lost'])->orderBy('id')->get();
            $keep = $leads->shift();
            $this->line(($dry ? '[dry-run] ' : '') . "contact #{$g->contact_id} (company {$g->company_id}, " . ($g->cat ?: 'no category') . "): keep lead #{$keep->id}, join " . $leads->pluck('id')->implode(', #'));
            if ($dry) { $merged += $leads->count(); continue; }

            DB::transaction(function () use ($keep, $leads) {
                foreach ($leads as $dup) {
                    foreach (self::LEAD_ID_TABLES as $table) {
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'lead_id')) {
                            DB::table($table)->where('lead_id', $dup->id)->update(['lead_id' => $keep->id]);
                        }
                    }
                    $patch = [];
                    if (!$keep->assigned_to && $dup->assigned_to) { $patch['assigned_to'] = $dup->assigned_to; $patch['assigned_at'] = $dup->assigned_at; }
                    if (!$keep->source && $dup->source) { $patch['source'] = $dup->source; }
                    if (filled($dup->notes)) { $patch['notes'] = trim(($keep->notes ? $keep->notes . "\n" : '') . "[merged from lead #{$dup->id}] " . $dup->notes); }
                    if ($patch) { $keep->forceFill($patch)->save(); }
                    LeadEvent::create(['lead_id' => $keep->id, 'company_id' => $keep->company_id, 'user_id' => null, 'event' => 'lead_merged',
                        'payload' => ['merged_lead_id' => $dup->id, 'stage' => $dup->stage, 'created_at' => $dup->created_at?->toDateTimeString()]]);
                    $dup->delete();   // soft delete
                }
            });
            $merged += $leads->count();
        }

        $this->info(($dry ? 'Would merge ' : 'Merged ') . "{$merged} repeated lead(s) across {$groups->count()} contact group(s).");
        return self::SUCCESS;
    }
}
