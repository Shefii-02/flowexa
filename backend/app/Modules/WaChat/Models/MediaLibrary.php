<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;
use App\Models\User;

class MediaLibrary extends Model
{
    protected $table = 'media_library';

    protected $fillable = [
        'company_id',
        'folder',
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
        return $this->belongsTo(MediaFolder::class, 'folder');
    }
    public function getFileUrlAttribute(): string
    {
        return $this->url;
    }
}
