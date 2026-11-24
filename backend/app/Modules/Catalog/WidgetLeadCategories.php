<?php

namespace App\Modules\Catalog;

use App\Models\FlowNode;
use App\Models\Lead;
use App\Models\WidgetConversation;
use App\Services\LeadCategorizer;

/**
 * A website-widget visitor who taps through a flow picks up the lead categories set on the nodes they tap. They are remembered on the
 * conversation (collected.lead_categories) the moment they tap, and written to the visitor's lead as soon as there is one — when the
 * visitor asks for a person the lead is created, and every category collected so far goes onto it.
 */
class WidgetLeadCategories
{
    /** The visitor just reached this node: remember its category and put it on the lead if there already is one. */
    public static function noteTap(WidgetConversation $convo, FlowNode $node): void
    {
        $names = self::split((string) $node->lead_category);
        if (!$names) { return; }
        $have = (array) ($convo->collected['lead_categories'] ?? []);
        $known = array_map('mb_strtolower', $have);
        foreach ($names as $n) {
            if (!in_array(mb_strtolower($n), $known, true)) { $have[] = $n; $known[] = mb_strtolower($n); }
        }
        $convo->update(['collected' => array_merge((array) ($convo->collected ?? []), ['lead_categories' => array_values($have)])]);
        self::sync($convo);
    }

    /** Put every collected category on the conversation's lead (adds only; nothing the staff set is removed). */
    public static function sync(WidgetConversation $convo): void
    {
        $names = (array) ($convo->collected['lead_categories'] ?? []);
        if (!$names || !$convo->lead_id) { return; }
        try {
            $lead = Lead::find($convo->lead_id);
            if (!$lead) { return; }
            [$before, $after] = app(LeadCategorizer::class)->add($lead, $names);
            if ($before !== $after) {
                app(\App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface::class)->logEvent($lead, 'category_changed', ['from' => $before, 'to' => $after, 'via' => 'website widget flow']);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Widget flow categories: could not save to the lead', ['error' => $e->getMessage()]);
        }
    }

    /** "Admission, Fees" → ["Admission", "Fees"] */
    public static function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $value) ?: []), fn ($v) => $v !== ''));
    }
}
