<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use App\Models\Company;
use App\Models\User;

class MediaLibrary extends Model
{
    protected $table = 'media_library';

    protected $fillable = [
        'company_id',
        'folder',
        'folder_id',
        'filename',
        'original_name',
        'display_name',
        'url',
        'disk',
        'path',
        'size',
        'mime_type',
        'uploaded_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }
    // `url` is stored at upload time as whatever Storage::disk('public')->url() resolved to THEN
    // (config/filesystems.php builds it from APP_URL) — so any row uploaded before the app's public
    // domain changed (e.g. flowexa-api.univexa.in -> api.teamzo.io) keeps serving the old, now-dead
    // domain forever, breaking both the browser preview and the WA gateway's own fetch of it when
    // sending. Overriding the accessor for the real `url` column (not a separate virtual attribute)
    // means every read — API responses, toArray(), the old dead getFileUrlAttribute() below — always
    // reflects the CURRENT domain from path+disk, with no migration/backfill needed for old rows.
    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return $this->attributes['url'] ?? null;
        }
        return Storage::disk($this->disk ?: 'public')->url($this->path);
    }

    public function getFileUrlAttribute(): ?string
    {
        return $this->url;
    }
}
