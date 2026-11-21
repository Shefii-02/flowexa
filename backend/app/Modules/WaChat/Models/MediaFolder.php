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
        'company_id', 'parent_id', 'team_chat_id', 'name', 'slug', 'permissions', 'is_system', 'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system'   => 'boolean',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function files(): HasMany     { return $this->hasMany(MediaLibrary::class, 'folder_id'); }

    /** Per-request cache of a company's folders by id, so walking up the parents costs one query per request, not one per folder. */
    private static array $byId = [];

    public static function forgetCache(): void { self::$byId = []; }

    private function parentFolder(): ?self
    {
        if (!$this->parent_id) { return null; }
        return self::$byId[$this->parent_id] ??= static::find($this->parent_id);
    }

    /** Folders above this one, nearest first (a loop guard keeps a bad cycle from hanging anything). @return self[] */
    public function ancestors(): array
    {
        $out = []; $p = $this->parentFolder();
        while ($p && count($out) < 8) { $out[] = $p; $p = $p->parentFolder(); }
        return $out;
    }

    /** 1 = top level, 2 = inside a folder, 3 = inside a folder inside a folder. */
    public function depth(): int { return 1 + count($this->ancestors()); }

    /** Returns true when the given user may view this folder and its contents — a folder is only open to someone who can open EVERY folder above it too. */
    public function canAccess(User $user): bool
    {
        foreach ($this->ancestors() as $parent) {
            if (!$parent->ownAccess($user)) { return false; }
        }
        return $this->ownAccess($user);
    }

    private function ownAccess(User $user): bool
    {
        // Team chat files are private conversations: only the owner and chat moderators may browse them here.
        if ($this->is_system && ($this->slug === 'team-chat' || $this->team_chat_id)) {
            return $user->isSuperAdmin() || $user->isOwner() || $user->hasPermission('team_chat.moderate');
        }
        if (empty($this->permissions)) return true;
        $roleName = $user->role?->name ?? '';
        return in_array($roleName, $this->permissions, true);
    }

    public static function systemSlugs(): array
    {
        return ['images', 'videos', 'audio', 'documents', 'team-chat', 'wa-chat'];
    }
}
