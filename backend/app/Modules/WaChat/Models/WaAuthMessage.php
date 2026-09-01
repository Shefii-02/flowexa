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

    protected $attributes = ['is_active' => true];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public static function defaultTemplates(int $companyId, string $companyName = 'Us'): array
    {
        return [
            ['company_id' => $companyId, 'name' => 'OTP Verification', 'type' => 'otp', 'sort_order' => 1, 'is_active' => true,
             'message_template' => "🔐 Your {{company_name}} verification code is *{{otp}}*.\nValid for {{expiry}} minutes. Do not share this code with anyone."],
            ['company_id' => $companyId, 'name' => 'App Login OTP', 'type' => 'otp', 'sort_order' => 2, 'is_active' => true,
             'message_template' => "🔑 {{website/app_name}} Login Code: *{{otp}}*\nThis OTP is valid for {{expiry}} minutes.\nIf you didn't request this, ignore the message."],
            ['company_id' => $companyId, 'name' => 'Registration OTP', 'type' => 'otp', 'sort_order' => 3, 'is_active' => true,
             'message_template' => "👋 Welcome to {{company_name}}!\nYour registration OTP is *{{otp}}*.\nPlease enter this code to verify your account. Expires in {{expiry}} minutes."],
            ['company_id' => $companyId, 'name' => 'Password Reset OTP', 'type' => 'password_reset', 'sort_order' => 4, 'is_active' => true,
             'message_template' => "🔄 {{website/app_name}} Password Reset\nYour reset code is *{{otp}}*.\nThis code expires in {{expiry}} minutes. Do not share."],
            ['company_id' => $companyId, 'name' => 'Transaction OTP', 'type' => 'otp', 'sort_order' => 5, 'is_active' => true,
             'message_template' => "💳 {{company_name}} Transaction OTP\nAuthorise your transaction with code: *{{otp}}*\nValid for {{expiry}} minutes. Never share this OTP."],
        ];
    }

    public static function defaultUtilityTemplates(int $companyId, string $companyName = 'Us'): array
    {
        return [
            ['company_id' => $companyId, 'name' => 'Welcome Greeting', 'type' => 'welcome', 'sort_order' => 10, 'is_active' => true,
             'message_template' => "👋 Welcome to {$companyName}!\n\nThank you for contacting us. We're here to help. How can we assist you today?"],
            ['company_id' => $companyId, 'name' => 'Payment Reminder', 'type' => 'payment_reminder', 'sort_order' => 11, 'is_active' => true,
             'message_template' => "💳 Payment Reminder from {$companyName}\n\nThis is a friendly reminder that your payment is due. Please complete your payment at the earliest convenience.\n\nFor queries, reply to this message."],
            ['company_id' => $companyId, 'name' => 'Appointment Reminder', 'type' => 'appointment', 'sort_order' => 12, 'is_active' => true,
             'message_template' => "📅 Appointment Reminder - {$companyName}\n\nYour appointment is scheduled for {{date}} at {{time}}.\n\nPlease reply to confirm or reschedule."],
            ['company_id' => $companyId, 'name' => 'Order Confirmation', 'type' => 'utility', 'sort_order' => 13, 'is_active' => true,
             'message_template' => "✅ Order Confirmed - {$companyName}\n\nThank you for your order! We've received it and will process it shortly.\n\nFor updates, stay tuned on this number."],
            ['company_id' => $companyId, 'name' => 'Delivery Update', 'type' => 'utility', 'sort_order' => 14, 'is_active' => true,
             'message_template' => "🚚 Delivery Update - {$companyName}\n\nYour order is on the way! Expected delivery: {{date}}.\n\nThank you for choosing {$companyName}."],
            ['company_id' => $companyId, 'name' => 'Invoice Ready', 'type' => 'utility', 'sort_order' => 15, 'is_active' => true,
             'message_template' => "🧾 Your invoice from {$companyName} is ready. Please find it attached.\n\nFor queries, reply to this message."],
            ['company_id' => $companyId, 'name' => 'Follow Up', 'type' => 'custom', 'sort_order' => 16, 'is_active' => true,
             'message_template' => "👋 Hi! This is {$companyName} following up on your recent enquiry.\n\nAre you still interested? We'd love to assist you. Please reply YES to continue."],
            ['company_id' => $companyId, 'name' => 'Thank You', 'type' => 'custom', 'sort_order' => 17, 'is_active' => true,
             'message_template' => "🙏 Thank you for choosing {$companyName}!\n\nWe appreciate your business. Feel free to reach out anytime.\n\nHave a wonderful day! 😊"],
        ];
    }
}
