<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAiConfig extends Model
{
    protected $fillable = [
        'company_id', 'is_enabled',
        'meta_app_id', 'meta_app_secret', 'meta_access_token',
        'meta_phone_number_id', 'meta_waba_id',
        'meta_ai_enabled', 'meta_ai_model', 'meta_ai_api_key',
        'analyze_on_message', 'analyze_sentiment', 'detect_buying_signals',
        'auto_qualify_leads', 'auto_create_tasks', 'hand_off_threshold',
        'inject_company_profile', 'inject_services', 'inject_pricing',
        'inject_past_conversations', 'max_context_messages',
    ];

    protected $hidden = ['meta_app_secret', 'meta_access_token', 'meta_ai_api_key'];

    protected $casts = [
        'is_enabled'             => 'boolean',
        'meta_ai_enabled'        => 'boolean',
        'analyze_on_message'     => 'boolean',
        'analyze_sentiment'      => 'boolean',
        'detect_buying_signals'  => 'boolean',
        'auto_qualify_leads'     => 'boolean',
        'auto_create_tasks'      => 'boolean',
        'inject_company_profile' => 'boolean',
        'inject_services'        => 'boolean',
        'inject_pricing'         => 'boolean',
        'inject_past_conversations' => 'boolean',
        'hand_off_threshold'     => 'float',
        'max_context_messages'   => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
