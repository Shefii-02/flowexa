<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "These leads go only to these employees" — by lead category, or by campaign / campaign period. */
class LeadRoutingRule extends Model
{
    protected $fillable = [
        'company_id', 'name', 'match_type', 'category', 'campaign_id', 'starts_at', 'ends_at',
        'staff_scope', 'staff_ids', 'strategy', 'is_active', 'priority',
    ];

    protected $casts = [
        'staff_ids' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /** First live rule (lowest priority number) that matches this lead's category / campaign right now. */
    /** @param string|string[]|null $category a lead's category, or all of its categories — the rule fires if it matches ANY of them */
    public static function matchFor(int $companyId, string|array|null $category, ?int $campaignId): ?self
    {
        $cats = array_values(array_filter(array_map(fn ($c) => mb_strtolower(trim((string) $c)), (array) $category), 'strlen'));
        $now = now();
        return static::where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('priority')->orderBy('id')->get()
            ->first(function (self $r) use ($cats, $campaignId) {
                // a selected rule needs its employees; an all / online rule needs none
                if (in_array($r->staff_scope ?: 'selected', ['selected', 'selected_online', 'selected_any'], true) && !$r->staff_ids) { return false; }
                if ($r->match_type === 'category') {
                    return $cats && $r->category && in_array(mb_strtolower(trim($r->category)), $cats, true);
                }
                // campaign: a specific one, or (campaign_id null) any campaign lead inside the period
                return $campaignId && (!$r->campaign_id || (int) $r->campaign_id === (int) $campaignId);
            });
    }
}
