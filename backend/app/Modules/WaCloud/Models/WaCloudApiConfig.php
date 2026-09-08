<?php

namespace App\Modules\WaCloud\Models;

use App\Models\Company;
use App\Models\PrebuiltTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-owned WA Cloud Api Service config, each backed by a real Meta
 * message template.
 * @see database/migrations/2026_09_08_000002_create_wa_cloud_api_configs_table.php
 */
class WaCloudApiConfig extends Model
{
    protected $table = 'wa_cloud_api_configs';

    public const KINDS = ['auth', 'utility', 'invoice'];

    /** Template states that can still be (re)submitted to Meta. */
    public const EDITABLE_STATUSES = ['draft', 'rejected', 'error'];

    /** Template states that are locked on Meta's side — edits must go through a duplicate. */
    public const LOCKED_STATUSES = ['approved', 'pending', 'pending_deletion', 'disabled'];

    protected $fillable = [
        'company_id', 'kind', 'name',
        'prebuilt_template_id', 'custom_content',
        'otp_length', 'otp_expiry_minutes', 'max_attempts',
        'caption', 'is_active', 'sort_order',
        'wa_template_id', 'template_name', 'template_language', 'template_category',
        'template_status', 'rejection_reason', 'template_submitted_at',
        'body_text', 'body_variable_names', 'body_examples', 'footer_text',
        'header_format', 'header_handle', 'header_sample_path', 'header_sample_url',
        'auth_delivery_method', 'auth_apps', 'auth_add_expiry',
        'auth_code_expiration_minutes', 'auth_add_security_recommendation',
        'auth_zero_tap_terms_accepted',
    ];

    protected $attributes = ['is_active' => true, 'sort_order' => 100];

    protected $casts = [
        'is_active'                        => 'boolean',
        'otp_length'                       => 'integer',
        'otp_expiry_minutes'               => 'integer',
        'max_attempts'                     => 'integer',
        'sort_order'                       => 'integer',
        'template_submitted_at'            => 'datetime',
        'body_variable_names'              => 'array',
        'body_examples'                    => 'array',
        'auth_apps'                        => 'array',
        'auth_add_expiry'                  => 'boolean',
        'auth_code_expiration_minutes'     => 'integer',
        'auth_add_security_recommendation' => 'boolean',
        'auth_zero_tap_terms_accepted'     => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function prebuiltTemplate(): BelongsTo
    {
        return $this->belongsTo(PrebuiltTemplate::class, 'prebuilt_template_id');
    }

    public function scopeKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Own `custom_content` if set, otherwise the linked library template's content. */
    public function resolveContent(): ?string
    {
        if (filled($this->custom_content)) {
            return $this->custom_content;
        }

        return $this->prebuiltTemplate?->content;
    }

    public function isSubmittable(): bool
    {
        return in_array($this->template_status, self::EDITABLE_STATUSES, true);
    }

    public function isSendable(): bool
    {
        return $this->template_status === 'approved';
    }
}
