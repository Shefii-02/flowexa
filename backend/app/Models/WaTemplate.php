<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// WaTemplate
// ════════════════════════════════════════════════════════════════════════════
class WaTemplate extends Model
{

    protected $fillable = [
        'company_id',
        'wa_phone_number_id',
        'name',
        'wa_template_id',
        'category',
        'template_type',
        'carousel_media_format',
        'carousel_cards',
        'carousel_media',
        'lto_text',
        'lto_has_expiration',
        'lto_expiry_hours',
        'language',
        'body',
        'body_examples',
        'header',
        'header_format',
        'header_handle',
        'header_sample_path',
        'header_sample_url',
        'header_example',
        'footer',
        'buttons',
        'message_send_ttl_seconds',
        'status',
        'rejection_reason',
        // AUTHENTICATION-only fields — these were previously omitted, so a
        // freshly created auth template silently lost its delivery method / apps
        // until it was saved a second time (TemplateService::create mass-assigns
        // them via WaTemplate::create).
        'auth_delivery_method',
        'auth_add_expiry',
        'auth_code_expiration_minutes',
        'auth_add_security_recommendation',
        'auth_apps',
        'auth_zero_tap_terms_accepted',
    ];

    protected $casts = [
        'body_examples'                    => 'array',
        'buttons'                          => 'array',
        'carousel_cards'                   => 'array',
        'carousel_media'                   => 'array',
        'lto_has_expiration'               => 'boolean',
        'lto_expiry_hours'                 => 'integer',
        'auth_apps'                        => 'array',
        'auth_add_expiry'                  => 'boolean',
        'auth_code_expiration_minutes'     => 'integer',
        'message_send_ttl_seconds'         => 'integer',
        'auth_add_security_recommendation' => 'boolean',
        'auth_zero_tap_terms_accepted'     => 'boolean',
    ];

    // protected $fillable = [
    //     'company_id', 'name', 'wa_template_id', 'category',
    //     'language', 'body', 'header', 'footer', 'variables', 'status',
    // ];

    // protected $casts = ['variables' => 'array'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
    }
    public function waPhoneNumber(): BelongsTo
    {
        return $this->belongsTo(WaPhoneNumber::class, 'wa_phone_number_id');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }
}
