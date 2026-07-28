<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// PermissionOverride
// ════════════════════════════════════════════════════════════════════════════
class PermissionOverride extends Model
{
    protected $fillable = ['role_id','company_id','permissions','updated_by'];

    protected $casts = ['permissions' => 'array'];

    public function role(): BelongsTo      { return $this->belongsTo(Role::class); }
    public function company(): BelongsTo   { return $this->belongsTo(Company::class); }
    public function updater(): BelongsTo   { return $this->belongsTo(User::class,'updated_by'); }
}
