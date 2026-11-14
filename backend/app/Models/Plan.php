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
        'is_public', 'archived_at', 'modules', 'lead_assign_methods', 'max_media_storage_mb', 'max_campaigns_per_month', 'max_chat_campaigns', 'max_chat_campaigns_per_month', 'max_chat_campaign_recipients', 'max_surveys', 'max_short_links', 'sort_order', 'is_popular',
    ];

    protected $appends = ['yearly_price', 'sections'];

    protected $casts = [
        'features'              => 'array',
        'modules'               => 'array',
        'lead_assign_methods'   => 'array',
        'is_popular'            => 'boolean',
        'is_public'             => 'boolean',
        'archived_at'           => 'datetime',
        'max_media_storage_mb'  => 'integer',
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

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
