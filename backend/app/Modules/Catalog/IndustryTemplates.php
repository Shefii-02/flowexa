<?php

namespace App\Modules\Catalog;

use App\Models\IndustryTemplate;
use Illuminate\Support\Facades\Cache;

/**
 * Accessor over the industry_templates DB table (SuperAdmin-managed — see
 * AgentPlaybookTemplateAdminController's sibling, IndustryTemplateAdminController) so
 * callers don't spread queries/config lookups. Used to live entirely in
 * config/industry_templates.php; moved to the database so a new vertical can be added
 * without a code deploy. The static API here is unchanged on purpose — every existing
 * caller (WidgetController, CompanyStarterKit, ListingMatcher, AgentContext…) keeps working
 * without modification.
 *
 * Cached briefly since this is read on nearly every AI agent turn and industry templates
 * change rarely; IndustryTemplateAdminController clears the cache on every write.
 */
class IndustryTemplates
{
    private const CACHE_KEY = 'industry_templates:all';
    private const CACHE_TTL = 3600;

    /** @return array<string, array> keyed by template key, same shape as the old config file */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return IndustryTemplate::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn (IndustryTemplate $t) => [$t->key => $t->toLegacyArray()])
                ->all();
        });
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(?string $key): array
    {
        $all = self::all();
        return $all[$key] ?? $all['generic'] ?? [];
    }

    /** The attribute-schema entries flagged `matchable` — what ListingMatcher scores. */
    public static function matchableFields(?string $key): array
    {
        return array_values(array_filter(
            self::get($key)['attribute_schema'] ?? [],
            fn ($f) => !empty($f['matchable']),
        ));
    }

    public static function listingType(?string $key): string
    {
        return self::get($key)['listing_type'] ?? 'product';
    }

    /** A compact catalog block for injecting into an LLM prompt. */
    public static function agentPrompt(?string $key): string
    {
        return self::get($key)['agent_prompt'] ?? '';
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
