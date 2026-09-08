<?php

namespace App\Modules\Catalog;

/**
 * Thin accessor over config/industry_templates.php so callers don't spread `config()` lookups.
 */
class IndustryTemplates
{
    public static function all(): array
    {
        return config('industry_templates', []);
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
}
