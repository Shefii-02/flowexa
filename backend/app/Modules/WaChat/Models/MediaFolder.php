<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Company;
use App\Models\User;

class MediaFolder extends Model
{
    protected $table = 'media_folders';

    protected $fillable = [
        'company_id', 'name', 'slug', 'permissions', 'is_system', 'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system'   => 'boolean',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function files(): HasMany     { return $this->hasMany(MediaLibrary::class, 'folder_id'); }

    /** Returns true when the given user may view this folder and its contents. */
    public function canAccess(User $user): bool
    {
        if (empty($this->permissions)) return true;
        $roleName = $user->role?->name ?? '';
        return in_array($roleName, $this->permissions, true);
    }

    public static function systemSlugs(): array
    {
        return ['images', 'videos', 'audio', 'documents'];
    }
}
