<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Company;

class WaOtpService extends Model
{
    protected $table = 'openwa_otp_services';

    protected $fillable = [
        'company_id', 'api_token', 'api_token_created_at', 'is_active',
        'allowed_domains', 'allowed_packages', 'allow_non_browser', 'allowed_ips', 'require_signature', 'signing_secret', 'rate_per_minute', 'max_per_phone_hour', 'otp_expiry_minutes',
        'otp_length', 'otp_message_template', 'session_id',
        'delivery_channel', 'wa_phone_number_id',
    ];

    protected $hidden = ['api_token', 'signing_secret'];

    protected $casts = [
        'is_active'           => 'boolean',
        'allow_non_browser'   => 'boolean',
        'allowed_ips'         => 'array',
        'require_signature'   => 'boolean',
        'signing_secret'      => 'encrypted',
        'rate_per_minute'     => 'integer',
        'max_per_phone_hour'  => 'integer',
        'allowed_domains'     => 'array',
        'allowed_packages'    => 'array',
        'api_token_created_at'=> 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function codes(): HasMany { return $this->hasMany(WaOtpCode::class, 'service_id'); }
    public function logs(): HasMany { return $this->hasMany(WaOtpLog::class, 'service_id'); }
    public function configs(): HasMany { return $this->hasMany(WaApiConfig::class, 'company_id', 'company_id'); }
}
