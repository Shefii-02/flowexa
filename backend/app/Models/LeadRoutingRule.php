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
    public static function matchFor(int $companyId, ?string $category, ?int $campaignId): ?self
    {
        $now = now();
        return static::where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('priority')->orderBy('id')->get()
            ->first(function (self $r) use ($category, $campaignId) {
                // a selected rule needs its employees; an all / online rule needs none
                if (in_array($r->staff_scope ?: 'selected', ['selected', 'selected_online'], true) && !$r->staff_ids) { return false; }
                if ($r->match_type === 'category') {
                    return $category && $r->category && mb_strtolower(trim($r->category)) === mb_strtolower(trim($category));
                }
                // campaign: a specific one, or (campaign_id null) any campaign lead inside the period
                return $campaignId && (!$r->campaign_id || (int) $r->campaign_id === (int) $campaignId);
            });
    }
}
