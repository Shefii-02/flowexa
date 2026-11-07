<?php

// ════════════════════════════════════════════════════════════════════════════
// app/Models/Plan.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'messages_limit', 'price', 'price_yearly', 'features', 'is_active',
        'duration_type', 'duration_months', 'alert_before_days', 'max_users', 'max_templates',
        'max_phone_numbers', 'max_wa_sessions', 'max_campaigns', 'max_contacts', 'max_labels',
        'max_flow_nodes', 'max_campaign_contacts', 'throttle_per_minute',
        'is_custom', 'custom_for_company_id',
        'max_leads_per_month', 'max_lead_categories', 'max_roles',
        'max_instagram_accounts', 'max_instagram_automations', 'max_instagram_dms_monthly', 'max_meta_ads_accounts', 'max_website_widgets',
        'google_sheets_enabled', 'google_drive_enabled', 'calendar_enabled', 'email_integration_enabled',
    ];

    protected $appends = ['yearly_price', 'sections'];

    protected $casts = [
        'features'              => 'array',
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

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
