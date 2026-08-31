<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;

class WaAuthMessage extends Model
{
    protected $table = 'wa_auth_messages';

    protected $fillable = [
        'company_id', 'name', 'type', 'message_template', 'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public static function defaultTemplates(int $companyId, string $companyName = 'Us'): array
    {
        return [
            ['company_id' => $companyId, 'name' => 'OTP Verification', 'type' => 'otp', 'sort_order' => 1,
             'message_template' => "Your verification code is {{otp}}. Valid for {{expiry}} minutes. Do not share."],
            ['company_id' => $companyId, 'name' => 'Welcome', 'type' => 'welcome', 'sort_order' => 2,
             'message_template' => "Welcome to {{company}}! Your account is ready. Reply HELP for assistance."],
            ['company_id' => $companyId, 'name' => 'Login Alert', 'type' => 'login_alert', 'sort_order' => 3,
             'message_template' => "New login detected on your account at {{time}}. Not you? Reply STOP."],
            ['company_id' => $companyId, 'name' => 'Password Reset', 'type' => 'password_reset', 'sort_order' => 4,
             'message_template' => "Your password reset code is {{otp}}. Ignore if not requested."],
        ];
    }
}
