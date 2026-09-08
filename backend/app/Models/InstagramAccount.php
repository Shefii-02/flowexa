<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'instagram_accounts';
    protected $guarded = [];

    protected $casts = [
        'access_token'          => 'encrypted',
        'followers_count'       => 'integer',
        'ai_enabled'            => 'boolean',
        'mirror_customer_style' => 'boolean',
        'is_active'             => 'boolean',
        'token_expires_at'      => 'datetime',
        'last_synced_at'        => 'datetime',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
        'deleted_at'            => 'datetime',
    ];

    protected $hidden = ['access_token'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function connectedBy(): BelongsTo { return $this->belongsTo(User::class, 'connected_by'); }
    public function automations(): HasMany { return $this->hasMany(InstagramAutomation::class); }
    public function conversations(): HasMany { return $this->hasMany(InstagramConversation::class); }
}
