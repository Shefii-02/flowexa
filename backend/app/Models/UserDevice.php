<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'device_uid', 'device_name', 'platform',
        'app_version', 'push_token', 'ip', 'user_agent', 'login_token_id',
        'last_active_at', 'revoked_at', 'revoked_by',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'revoked_at'     => 'datetime',
    ];

    protected $hidden = ['push_token'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(?int $by = null): void
    {
        $this->update(['revoked_at' => now(), 'revoked_by' => $by]);
    }
}
