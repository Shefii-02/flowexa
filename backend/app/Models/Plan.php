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
        'duration_type', 'duration_months', 'max_users', 'max_templates',
        'max_phone_numbers', 'max_campaigns', 'max_contacts', 'max_labels',
        'max_flow_nodes', 'max_campaign_contacts', 'throttle_per_minute',
        'is_custom', 'custom_for_company_id',
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
    ];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
