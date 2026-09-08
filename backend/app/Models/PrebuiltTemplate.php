<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row in the shared library of Meta pre-approved WhatsApp message templates
 * (authentication, utility and other categories). Not company-scoped — this is
 * reference data seeded by {@see \Database\Seeders\PrebuiltTemplateSeeder}.
 *
 * Each (name, language) pair is unique. `type` is a free-form category label
 * ("auth", "utility", "other", …) stored as a plain string so new categories
 * need no migration. `content` carries the localised body and `variables` lists
 * the {{placeholder}} tokens it expects, in order of first appearance.
 */
class PrebuiltTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'content',
        'variables',
        'language',
        'status',
    ];

    protected $casts = [
        'variables' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    /**
     * Ordered, de-duplicated list of {{placeholder}} tokens in a body. Shared by
     * {@see \Database\Seeders\PrebuiltTemplateSeeder} and the superadmin CRUD so
     * `variables` is always derived the same way.
     */
    public static function extractVariables(string $content): array
    {
        preg_match_all('/\{\{\s*(.+?)\s*\}\}/', $content, $matches);

        return array_values(array_unique($matches[1]));
    }
}
