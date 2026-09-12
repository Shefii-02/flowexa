<?php

namespace App\Models;

use App\Models\LeadAssignment;
use App\Models\StaffAvailability;
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
    public function leads(): HasMany        { return $this->hasMany(Lead::class, 'assigned_to'); }
    public function leadEvents(): HasMany  { return $this->hasMany(LeadEvent::class); }
    public function availability(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StaffAvailability::class, 'staff_id');
    }
    public function leadAssignments(): HasMany { return $this->hasMany(LeadAssignment::class, 'staff_id'); }
    public function devices(): HasMany { return $this->hasMany(UserDevice::class); }

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

    /** Permission key that fully unrestricts each account_type — see StaffAccountAccess. */
    private const ACCOUNT_VIEW_ALL_PERMISSION = [
        'wa_session'        => 'wa_chat.sessions.view_all',
        'phone_number'      => 'inbox.view_all',
        'instagram_account' => 'instagram.view_all',
        'meta_ads_account'  => 'meta_ads.view_all',
    ];

    /**
     * The account ids of the given type this user may see, or null when unrestricted.
     *
     * Restriction is opt-in: a user with the matching "view_all" permission, or one
     * who has never been given any StaffAccountAccess row of this type at all, is
     * unrestricted — so granting a company multiple WA Chat sessions / WA Cloud numbers
     * / Instagram accounts / ad accounts never silently locks existing staff out of
     * everything. Restriction only starts once an admin explicitly grants at least one
     * row for that (user, account_type).
     */
    public function allowedAccountIds(string $type): ?array
    {
        if ($this->isSuperAdmin() || $this->isOwner()) return null;

        $viewAllPermission = self::ACCOUNT_VIEW_ALL_PERMISSION[$type] ?? null;
        if ($viewAllPermission && $this->hasPermission($viewAllPermission)) return null;

        $ids = StaffAccountAccess::where('user_id', $this->id)->where('account_type', $type)->pluck('account_id');
        return $ids->isEmpty() ? null : $ids->all();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeActive($q)  { return $q->where('is_active', true); }
    public function scopeForCompany($q, int $companyId) { return $q->where('company_id', $companyId); }
}
