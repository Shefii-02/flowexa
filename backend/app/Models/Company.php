<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'plan_id', 'name', 'slug', 'app_id', 'private_token',
        'email', 'phone', 'website', 'logo', 'status',
        'trial_ends_at', 'wa_phone_id', 'wa_access_token',
        'wa_business_id', 'settings',
    ];

    protected $hidden = ['private_token', 'wa_access_token'];

    protected $casts = [
        'settings'       => 'array',
        'trial_ends_at'  => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function plan(): BelongsTo        { return $this->belongsTo(Plan::class); }
    public function wallet(): HasOne         { return $this->hasOne(Wallet::class); }
    public function users(): HasMany         { return $this->hasMany(User::class); }
    public function contacts(): HasMany      { return $this->hasMany(Contact::class); }
    public function labels(): HasMany        { return $this->hasMany(ContactLabel::class); }
    public function flowNodes(): HasMany     { return $this->hasMany(FlowNode::class); }
    public function campaigns(): HasMany     { return $this->hasMany(Campaign::class); }
    public function leads(): HasMany         { return $this->hasMany(Lead::class); }
    public function messageLogs(): HasMany   { return $this->hasMany(MessageLog::class); }
    public function webhookLogs(): HasMany   { return $this->hasMany(WebhookLog::class); }
    public function templates(): HasMany     { return $this->hasMany(WaTemplate::class); }
    public function otpVerifications(): HasMany { return $this->hasMany(OtpVerification::class); }
    public function paymentOrders(): HasMany { return $this->hasMany(PaymentOrder::class); }

    // ── Accessors ─────────────────────────────────────────────────────────────
    public function getWaConnectedAttribute(): bool
    {
        return !empty($this->wa_phone_id);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────
    public function scopeActive($q)    { return $q->where('status', 'active'); }
    public function scopeSuspended($q) { return $q->where('status', 'suspended'); }
    public function scopeTrial($q)     { return $q->where('status', 'trial'); }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isActive(): bool    { return $this->status === 'active'; }
    public function isSuspended(): bool { return $this->status === 'suspended'; }
    public function isOnTrial(): bool   { return $this->status === 'trial'; }

     public function companyOwner(): HasOne
    {
        return $this->hasOne(User::class)
            ->whereHas('role', function ($query) {
                $query->where('name', 'owner');
            });
    }
}
