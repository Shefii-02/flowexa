<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCategoryLink;
use Illuminate\Support\Facades\DB;

/**
 * A lead can hold several categories. leads.category is the PRIMARY one (what every single-category screen, export and
 * flow still reads); lead_category_links holds all of them, primary included.
 *
 *   replace / add / remove / clear  change the set; the primary stays put while it is still in the set,
 *   otherwise the first remaining category takes over (null when none are left).
 */
class LeadCategorizer
{
    /** @return string[] primary first, then the rest in the order they were added */
    public function names(Lead $lead): array
    {
        return $lead->categoryNames();
    }

    // ── what the set WOULD be (no writes) ────────────────────────────────────
    /** @param string[] $names @return string[] */
    public function planReplace(Lead $lead, array $names): array { return $this->clean($names); }

    /** @param string[] $names @return string[] */
    public function planAdd(Lead $lead, array $names): array { return $this->clean(array_merge($this->names($lead), $names)); }

    /** @param string[] $names @return string[] */
    public function planRemove(Lead $lead, array $names): array
    {
        $drop = array_map('mb_strtolower', $this->clean($names));
        return array_values(array_filter($this->names($lead), fn ($n) => !in_array(mb_strtolower($n), $drop, true)));
    }

    // ── do it ────────────────────────────────────────────────────────────────
    /** Write exactly this set. @param string[] $after */
    public function apply(Lead $lead, array $after): void
    {
        $this->write($lead, $this->names($lead), $this->clean($after));
    }

    /** @param string[] $names @return array{0:string[],1:string[]} [before, after] */
    public function replace(Lead $lead, array $names): array
    {
        $before = $this->names($lead); $after = $this->planReplace($lead, $names);
        $this->write($lead, $before, $after);
        return [$before, $after];
    }

    /** @param string[] $names @return array{0:string[],1:string[]} */
    public function add(Lead $lead, array $names): array
    {
        $before = $this->names($lead); $after = $this->planAdd($lead, $names);
        $this->write($lead, $before, $after);
        return [$before, $after];
    }

    /** @param string[] $names @return array{0:string[],1:string[]} */
    public function remove(Lead $lead, array $names): array
    {
        $before = $this->names($lead); $after = $this->planRemove($lead, $names);
        $this->write($lead, $before, $after);
        return [$before, $after];
    }

    /** @return string[] trimmed, de-duplicated (case-insensitively), order kept */
    private function clean(array $names): array
    {
        $seen = []; $out = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n === '' || isset($seen[mb_strtolower($n)])) { continue; }
            $seen[mb_strtolower($n)] = true;
            $out[] = $n;
        }
        return $out;
    }

    private function write(Lead $lead, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }
        $primary = $lead->category && in_array(mb_strtolower($lead->category), array_map('mb_strtolower', $after), true)
            ? collect($after)->first(fn ($n) => mb_strtolower($n) === mb_strtolower($lead->category))
            : ($after[0] ?? null);

        DB::transaction(function () use ($lead, $after, $primary) {
            LeadCategoryLink::where('lead_id', $lead->id)->whereNotIn('name', $after ?: [''])->delete();
            $have = LeadCategoryLink::where('lead_id', $lead->id)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
            foreach ($after as $name) {
                if (!in_array(mb_strtolower($name), $have, true)) {
                    LeadCategoryLink::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'name' => $name]);
                }
            }
            // straight to the table: the model hook that mirrors single-category writes must not run for this one
            Lead::whereKey($lead->id)->update(['category' => $primary]);
        });
        $lead->setRelation('categoryLinks', LeadCategoryLink::where('lead_id', $lead->id)->orderBy('id')->get());
        $lead->forceFill(['category' => $primary])->syncOriginalAttribute('category');
    }
}
