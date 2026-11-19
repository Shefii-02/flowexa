<?php

// ════════════════════════════════════════════════════════════════════════════
// app/Models/Plan.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'messages_limit', 'price', 'price_yearly', 'features', 'is_active',
        'duration_type', 'duration_months', 'alert_before_days', 'max_users', 'max_templates',
        'max_phone_numbers', 'max_wa_sessions', 'max_campaigns', 'max_contacts', 'max_labels',
        'max_flow_nodes', 'max_campaign_contacts', 'throttle_per_minute',
        'is_custom', 'custom_for_company_id',
        'max_leads_per_month', 'max_lead_categories', 'max_roles',
        'max_instagram_accounts', 'max_instagram_automations', 'max_instagram_dms_monthly', 'max_meta_ads_accounts', 'max_website_widgets',
        'google_sheets_enabled', 'google_drive_enabled', 'calendar_enabled', 'email_integration_enabled',
        'is_public', 'archived_at', 'modules', 'permissions', 'complimentary', 'billing_options', 'lead_assign_methods', 'max_media_storage_mb', 'max_campaigns_per_month', 'max_chat_campaigns', 'max_chat_campaigns_per_month', 'max_chat_campaign_recipients', 'max_surveys', 'max_short_links', 'max_sticky_notes_per_user', 'max_todos_per_user', 'sort_order', 'is_popular',
    ];

    protected $appends = ['yearly_price', 'sections', 'billing_periods'];

    protected $casts = [
        'features'              => 'array',
        'modules'               => 'array',
        'permissions'           => 'array',
        'complimentary'         => 'array',
        'billing_options'       => 'array',
        'lead_assign_methods'   => 'array',
        'is_popular'            => 'boolean',
        'is_public'             => 'boolean',
        'archived_at'           => 'datetime',
        'max_media_storage_mb'  => 'integer',
        'max_sticky_notes_per_user' => 'integer',
        'max_todos_per_user'    => 'integer',
        'max_campaigns_per_month' => 'integer',
        'max_chat_campaigns' => 'integer',
        'max_chat_campaigns_per_month' => 'integer',
        'max_chat_campaign_recipients' => 'integer',
        'max_surveys'           => 'integer',
        'max_short_links'       => 'integer',
        'is_active'             => 'boolean',
        'is_custom'             => 'boolean',
        'messages_limit'        => 'integer',
        'price'                 => 'decimal:2',
        'price_yearly'          => 'decimal:2',
        'duration_months'       => 'integer',
        'max_users'             => 'integer',
        'max_templates'         => 'integer',
        'max_phone_numbers'     => 'integer',
        'max_campaigns'         => 'integer',
        'max_contacts'          => 'integer',
        'max_labels'            => 'integer',
        'max_flow_nodes'        => 'integer',
        'max_campaign_contacts' => 'integer',
        'throttle_per_minute'   => 'integer',
        'max_leads_per_month'      => 'integer',
        'max_lead_categories'      => 'integer',
        'max_roles'                => 'integer',
        'max_instagram_accounts'   => 'integer',
        'max_instagram_automations' => 'integer',
        'max_instagram_dms_monthly' => 'integer',
        'max_meta_ads_accounts'    => 'integer',
        'max_website_widgets'      => 'integer',
        'google_sheets_enabled'      => 'boolean',
        'google_drive_enabled'       => 'boolean',
        'calendar_enabled'           => 'boolean',
        'email_integration_enabled'  => 'boolean',
    ];

    /** What a 12-month purchase costs: the price set by the superadmin, else 12 months at 20% off (same rule as PlanPurchaseService). */
    public function getYearlyPriceAttribute(): float
    {
        return $this->price_yearly !== null ? (float) $this->price_yearly : round((float) $this->price * 12 * 0.80, 2);
    }

    /** Limits grouped by feature, for the plan cards. */
    public function getSectionsAttribute(): array
    {
        return \App\Support\PlanLimits::sectionsFor($this);
    }

    /** Default offer for plans that pre-date billing options: % off per period. */
    public const DEFAULT_BILLING = ['3month' => 5, '6month' => 10, 'yearly' => 20];

    /** The periods this plan sells with their % off (monthly is always sold). null column = the default offer. */
    public function billingOffer(): array
    {
        $o = is_array($this->billing_options) ? $this->billing_options : self::DEFAULT_BILLING;
        $out = [];
        foreach (self::DEFAULT_BILLING as $k => $_) {
            if (array_key_exists($k, $o)) { $out[$k] = max(0, min(90, (float) $o[$k])); }
        }
        return $out;
    }

    public function getBillingPeriodsAttribute(): array
    {
        $offer = $this->billingOffer();
        $rows = [['key' => 'monthly', 'label' => '1 Month', 'months' => 1, 'discount' => 0]];
        foreach (['3month' => ['3 Months', 3], '6month' => ['6 Months', 6], 'yearly' => ['12 Months', 12]] as $k => [$label, $months]) {
            if (!isset($offer[$k])) { continue; }
            $d = $offer[$k];
            if ($k === 'yearly' && $this->price_yearly !== null && (float) $this->price > 0) {
                $d = round((1 - (float) $this->price_yearly / ((float) $this->price * 12)) * 100, 1);
            }
            $rows[] = ['key' => $k, 'label' => $label, 'months' => $months, 'discount' => $d];
        }
        return $rows;
    }

    /** Whether a duration may be bought on this plan. Durations outside the four standard ones (custom, unlimited) are not restricted. */
    public function offersDuration(string $durationType): bool
    {
        $k = $durationType === '12month' ? 'yearly' : $durationType;
        if (!in_array($k, ['monthly', '3month', '6month', 'yearly'], true) || $k === 'monthly') { return true; }
        return array_key_exists($k, $this->billingOffer());
    }

    /** Plans anyone may buy: on sale, public, not archived and not a one-off custom plan. */
    public function scopePurchasable($q)
    {
        return $q->where('is_active', true)->where('is_custom', false)->where('is_public', true)->whereNull('archived_at');
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && !$this->is_custom && $this->is_public && $this->archived_at === null;
    }

    /** Module keys this plan switches on (see App\Support\Modules). NULL on the row = every module (plans that pre-date toggles). */
    public function enabledModules(): array
    {
        return $this->modules === null ? \App\Support\Modules::keys() : array_values(array_intersect(\App\Support\Modules::keys(), $this->modules));
    }

    /** Complimentary features as [module => months], for modules the plan does not already include. */
    public function complimentaryMap(): array
    {
        $own = $this->enabledModules();
        $out = [];
        foreach ($this->complimentary ?? [] as $row) {
            $m = $row['module'] ?? null; $n = (int) ($row['months'] ?? 0);
            if ($m && $n > 0 && isset(\App\Support\Modules::REGISTRY[$m]) && !in_array($m, $own, true)) { $out[$m] = $n; }
        }
        return $out;
    }

    /** Whether this plan lets roles use a permission. Core permissions always; feature permissions unless the plan's list leaves them out. NULL list = all. */
    public function allowsPermission(string $permission): bool
    {
        if (\App\Support\Modules::permissionModule($permission) === null) return true;
        return $this->permissions === null || in_array($permission, $this->permissions, true);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
