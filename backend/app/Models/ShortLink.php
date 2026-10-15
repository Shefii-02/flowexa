<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortLink extends Model
{
    protected $fillable = [
        'company_id', 'code', 'target_url', 'title', 'clicks',
        'last_clicked_at', 'is_active', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'clicks'          => 'integer',
        'last_clicked_at' => 'datetime',
        'expires_at'      => 'datetime',
    ];

    protected $appends = ['short_url'];

    public function getShortUrlAttribute(): string
    {
        return rtrim(config('app.short_link_base') ?: config('app.url'), '/') . '/s/' . $this->code;
    }
}
