<?php

namespace App\Modules\WaCloud\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaCloudOtpCode extends Model
{
    protected $table = 'wa_cloud_otp_codes';

    protected $fillable = [
        'company_id', 'service_id', 'config_id', 'phone', 'otp_code', 'reference_id',
        'ip_address', 'domain', 'status', 'attempts', 'sent_at', 'verified_at', 'expires_at',
    ];

    protected $hidden = ['otp_code'];

    protected $casts = [
        'sent_at'     => 'datetime',
        'verified_at' => 'datetime',
        'expires_at'  => 'datetime',
        'attempts'    => 'integer',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function service(): BelongsTo { return $this->belongsTo(WaCloudOtpService::class, 'service_id'); }
    public function config(): BelongsTo { return $this->belongsTo(WaCloudApiConfig::class, 'config_id'); }
}
