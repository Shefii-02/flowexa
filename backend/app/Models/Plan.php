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
        'name', 'messages_limit', 'price', 'features', 'is_active',
        'duration_type', 'duration_months', 'alert_before_days', 'max_users', 'max_templates',
        'max_phone_numbers', 'max_wa_sessions', 'max_campaigns', 'max_contacts', 'max_labels',
        'max_flow_nodes', 'max_campaign_contacts', 'throttle_per_minute',
        'is_custom', 'custom_for_company_id',
        'max_leads_per_month', 'max_lead_categories', 'max_roles',
        'max_instagram_accounts', 'max_meta_ads_accounts', 'max_website_widgets',
        'google_sheets_enabled', 'google_drive_enabled', 'calendar_enabled', 'email_integration_enabled',
    ];

    protected $casts = [
        'features'              => 'array',
        'is_active'             => 'boolean',
        'is_custom'             => 'boolean',
        'messages_limit'        => 'integer',
        'price'                 => 'decimal:2',
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
        'max_meta_ads_accounts'    => 'integer',
        'max_website_widgets'      => 'integer',
        'google_sheets_enabled'      => 'boolean',
        'google_drive_enabled'       => 'boolean',
        'calendar_enabled'           => 'boolean',
        'email_integration_enabled'  => 'boolean',
    ];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
