<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Company;

class WaOtpService extends Model
{
    protected $table = 'wa_otp_services';

    protected $fillable = [
        'company_id', 'api_token', 'api_token_created_at', 'is_active',
        'allowed_domains', 'allowed_packages', 'otp_expiry_minutes',
        'otp_length', 'otp_message_template', 'session_id',
    ];

    protected $hidden = ['api_token'];

    protected $casts = [
        'is_active'           => 'boolean',
        'allowed_domains'     => 'array',
        'allowed_packages'    => 'array',
        'api_token_created_at'=> 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function codes(): HasMany { return $this->hasMany(WaOtpCode::class, 'service_id'); }
    public function logs(): HasMany { return $this->hasMany(WaOtpLog::class, 'service_id'); }
    public function authMessages(): HasMany { return $this->hasMany(WaAuthMessage::class, 'company_id', 'company_id'); }
}
