<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleIntegration extends Model
{
    use HasFactory;

    protected $table = 'google_integrations';
    protected $guarded = [];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes'           => 'array',
        'is_active'        => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function syncs(): HasMany { return $this->hasMany(GoogleSheetSync::class); }

    public function tokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->subMinute()->isPast();
    }
}
