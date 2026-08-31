<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;

class WaOtpLog extends Model
{
    protected $table = 'wa_otp_logs';

    protected $fillable = [
        'company_id', 'service_id', 'phone', 'action',
        'ip_address', 'domain', 'response_ms',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function service(): BelongsTo { return $this->belongsTo(WaOtpService::class, 'service_id'); }
}
