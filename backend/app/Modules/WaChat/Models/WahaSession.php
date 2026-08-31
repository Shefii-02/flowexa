<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Company;

class WahaSession extends Model
{
    protected $table = 'waha_sessions';

    protected $fillable = [
        'company_id', 'session_name', 'display_name', 'phone',
        'status', 'webhook_url', 'engine', 'last_seen_at',
    ];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(WahaWebhook::class, 'session_id');
    }
}
