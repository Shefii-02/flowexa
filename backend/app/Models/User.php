<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable, SoftDeletes;

    protected $fillable = [
        'company_id', 'role_id', 'name', 'email', 'phone',
        'avatar', 'department', 'password', 'is_active',
        'max_leads', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active'       => 'boolean',
        'last_login_at'   => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    // ── JWT ───────────────────────────────────────────────────────────────────
    public function getJWTIdentifier(): mixed        { return $this->getKey(); }
    public function getJWTCustomClaims(): array      { return []; }

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }
    public function role(): BelongsTo    { return $this->belongsTo(Role::class); }
    public function leads(): HasMany     { return $this->hasMany(Lead::class, 'assigned_to'); }
    public function leadEvents(): HasMany{ return $this->hasMany(LeadEvent::class); }

    // ── Permission helpers ────────────────────────────────────────────────────
    public function isSuperAdmin(): bool
    {
        return $this->role?->name === 'superadmin';
    }

    public function isOwner(): bool
    {
        return $this->role?->name === 'owner';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) return true;
        return $this->role?->hasPermission($permission) ?? false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isSuperAdmin()) return true;
        return $this->role?->hasAnyPermission($permissions) ?? false;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeActive($q)  { return $q->where('is_active', true); }
    public function scopeForCompany($q, int $companyId) { return $q->where('company_id', $companyId); }
}
