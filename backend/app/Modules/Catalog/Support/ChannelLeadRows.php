<?php

namespace App\Modules\Catalog\Support;

use App\Models\Lead;

/** Shared bits of the per-channel "Leads" pages (Instagram, Messenger, website widget): which leads these conversations became. */
class ChannelLeadRows
{
    /** @param array<int,int|null> $leadIds @return array<int,array{id:int,stage:string,assigned_to:?string}> keyed by lead id */
    public static function leads(array $leadIds): array
    {
        $ids = array_values(array_filter(array_unique($leadIds)));
        if (!$ids) { return []; }
        return Lead::whereIn('id', $ids)->with('assignedTo:id,name')->get()
            ->mapWithKeys(fn (Lead $l) => [$l->id => ['id' => $l->id, 'stage' => $l->stage, 'assigned_to' => $l->assignedTo?->name]])->all();
    }

    /** Ten or more digits make a usable phone for the lead; anything shorter is refused. */
    public static function cleanPhone(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        return strlen($d) >= 8 ? $d : '';
    }
}
