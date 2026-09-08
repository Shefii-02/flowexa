<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaMediaLibrary extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_media_library';

    protected $guarded = [];

    protected $casts = [
        'file_size'        => 'integer',
        'width'            => 'integer',
        'height'           => 'integer',
        'duration_seconds' => 'integer',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function adAccount(): BelongsTo { return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function creatives(): HasMany { return $this->hasMany(MetaAdCreative::class, 'image_id'); }
}
