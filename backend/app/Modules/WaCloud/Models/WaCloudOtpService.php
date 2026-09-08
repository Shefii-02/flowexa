<?php

namespace App\Modules\WaCloud\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company-level settings for the WA Cloud OTP Service (Meta Cloud API flavour).
 * @see database/migrations/2026_09_08_000001_create_wa_cloud_otp_services_table.php
 */
class WaCloudOtpService extends Model
{
    protected $table = 'wa_cloud_otp_services';

    protected $fillable = [
        'company_id', 'api_token', 'api_token_created_at', 'is_active',
        'allowed_domains', 'allowed_packages',
    ];

    protected $hidden = ['api_token'];

    protected $casts = [
        'is_active'            => 'boolean',
        'allowed_domains'      => 'array',
        'allowed_packages'     => 'array',
        'api_token_created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(WaCloudOtpCode::class, 'service_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WaCloudOtpLog::class, 'service_id');
    }
}
