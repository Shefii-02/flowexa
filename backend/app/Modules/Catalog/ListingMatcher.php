<?php

namespace App\Modules\Catalog;

use App\Models\Listing;
use Illuminate\Support\Collection;

/**
 * Smart matching: a lead states requirements in natural language, the agent extracts them into a
 * keyed array, and this ranks the company's live catalog against them.
 *
 * Scoring is deliberately simple and explainable:
 *  - budget           → listing price within budget scores full; slightly over tapers to 0
 *  - location         → substring match either direction
 *  - numeric attrs    → exact match full, within the field's `tolerance` partial
 *  - enum / text attrs→ case-insensitive equality / contains
 * Every requirement that has no counterpart on the listing is simply ignored (no penalty), so a
 * sparse listing still surfaces.
 */
class ListingMatcher
{
    public function match(int $companyId, ?string $industryTemplate, array $requirements, int $limit = 5): Collection
    {
        $type   = IndustryTemplates::listingType($industryTemplate);
        $fields = collect(IndustryTemplates::matchableFields($industryTemplate))->keyBy('key');

        $listings = Listing::where('company_id', $companyId)
            ->where('type', $type)
            ->active()
            ->orderBy('sort_order')
            ->limit(200)
            ->get();

        $budget   = $this->num($requirements['budget'] ?? $requirements['max_budget'] ?? null);
        $location = $this->str($requirements['location'] ?? $requirements['area'] ?? null);

        return $listings
            ->map(function (Listing $l) use ($requirements, $fields, $budget, $location) {
                $score = 0.0;
                $max   = 0.0;
                $why   = [];

                if ($budget !== null && $l->price !== null) {
                    $max += 3;
                    $price = (float) $l->price;
                    if ($price <= $budget) { $score += 3; $why[] = 'within budget'; }
                    elseif ($price <= $budget * 1.15) { $score += 1.5; $why[] = 'slightly over budget'; }
                }

                if ($location !== null && $l->location) {
                    $max += 3;
                    $a = mb_strtolower($l->location);
                    // Token overlap — "Kakkanad, Kochi" should match "kochi kakkanad" or just "kakkanad".
                    $wantWords = array_filter(preg_split('/[\s,]+/', $location), fn ($w) => mb_strlen($w) > 2);
                    $haveWords = array_filter(preg_split('/[\s,]+/', $a), fn ($w) => mb_strlen($w) > 2);
                    $hits = count(array_intersect($wantWords, $haveWords));
                    if ($hits > 0) {
                        $score += min(3, $hits * 2);
                        $why[] = 'in preferred area';
                    } elseif (str_contains($a, $location) || str_contains($location, $a)) {
                        $score += 3;
                        $why[] = 'in preferred area';
                    }
                }

                $attrs = $l->attributes ?? [];
                foreach ($fields as $key => $field) {
                    $want = $requirements[$key] ?? null;
                    if ($want === null || $want === '' || !array_key_exists($key, $attrs)) {
                        continue;
                    }
                    $max += 2;
                    $have = $attrs[$key];

                    if (($field['type'] ?? '') === 'number') {
                        $w = $this->num($want); $h = $this->num($have);
                        if ($w === null || $h === null) continue;
                        $tol = (float) ($field['tolerance'] ?? 0);
                        if (abs($h - $w) < 0.001) { $score += 2; $why[] = "{$key} matches"; }
                        elseif ($tol > 0 && abs($h - $w) <= $tol) { $score += 1; $why[] = "{$key} close"; }
                    } else {
                        $w = $this->str($want); $h = mb_strtolower((string) (is_bool($have) ? ($have ? 'yes' : 'no') : $have));
                        if ($w !== null && ($w === $h || str_contains($h, $w) || str_contains($w, $h))) {
                            $score += 2; $why[] = "{$key} matches";
                        }
                    }
                }

                return [
                    'listing' => $l,
                    'score'   => $max > 0 ? round($score / $max, 3) : 0.0,
                    'raw'     => $score,
                    'reasons' => $why,
                ];
            })
            ->sortByDesc('raw')
            ->take($limit)
            ->values();
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        $digits = preg_replace('/[^0-9.]/', '', (string) $v);
        return $digits === '' ? null : (float) $digits;
    }

    private function str(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        return mb_strtolower(trim((string) $v));
    }
}
