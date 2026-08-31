<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;

class WaOtpCode extends Model
{
    protected $table = 'wa_otp_codes';

    protected $fillable = [
        'company_id', 'service_id', 'phone', 'otp_code', 'reference_id',
        'ip_address', 'domain', 'status', 'attempts', 'sent_at', 'verified_at', 'expires_at',
    ];

    protected $hidden = ['otp_code'];

    protected $casts = [
        'sent_at'     => 'datetime',
        'verified_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function service(): BelongsTo { return $this->belongsTo(WaOtpService::class, 'service_id'); }
}
