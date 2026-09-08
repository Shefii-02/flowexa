<?php

namespace App\Modules\WaChat\Models;

use App\Models\Company;
use App\Models\PrebuiltTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-owned, named configuration for one public Api Service endpoint
 * family ({@see database/migrations/2026_09_06_000004_create_wa_api_configs_table.php}).
 *
 * `kind` is one of:
 *  - auth    : OTP send / verify / resend
 *  - utility : plain utility message send
 *  - invoice : document / invoice share
 */
class WaApiConfig extends Model
{
    protected $table = 'wa_api_configs';

    public const KINDS = ['auth', 'utility', 'invoice'];

    protected $fillable = [
        'company_id', 'kind', 'name',
        'prebuilt_template_id', 'custom_content',
        'otp_length', 'otp_expiry_minutes', 'max_attempts',
        'session_id',
        'file_url', 'filename', 'caption',
        'is_active', 'sort_order',
    ];

    protected $attributes = ['is_active' => true, 'sort_order' => 100];

    protected $casts = [
        'is_active'          => 'boolean',
        'otp_length'         => 'integer',
        'otp_expiry_minutes' => 'integer',
        'max_attempts'       => 'integer',
        'sort_order'         => 'integer',
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

    /**
     * The message body this config sends: its own `custom_content` if set,
     * otherwise the linked library template's content, otherwise null.
     */
    public function resolveContent(): ?string
    {
        if (filled($this->custom_content)) {
            return $this->custom_content;
        }

        return $this->prebuiltTemplate?->content;
    }
}
