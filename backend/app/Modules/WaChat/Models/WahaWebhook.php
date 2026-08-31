<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;

class WahaWebhook extends Model
{
    protected $table = 'waha_webhooks';

    protected $fillable = [
        'company_id', 'session_id', 'name', 'url', 'events',
        'secret', 'is_active', 'last_triggered_at', 'last_status_code',
    ];

    protected $casts = [
        'events'            => 'array',
        'is_active'         => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function session(): BelongsTo { return $this->belongsTo(WahaSession::class, 'session_id'); }
}
