<?php

namespace App\Modules\WaCloud\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaCloudOtpLog extends Model
{
    protected $table = 'wa_cloud_otp_logs';

    protected $fillable = [
        'company_id', 'service_id', 'config_id', 'phone', 'action',
        'wa_message_id', 'error', 'ip_address', 'domain', 'response_ms',
    ];

    protected $casts = [
        'response_ms' => 'integer',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function service(): BelongsTo { return $this->belongsTo(WaCloudOtpService::class, 'service_id'); }
    public function config(): BelongsTo { return $this->belongsTo(WaCloudApiConfig::class, 'config_id'); }
}
