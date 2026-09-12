<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per API request — the raw material behind both the superadmin
 * "API & Activity" performance dashboard (throughput, latency, error rate,
 * per-company breakdown) and the human-readable activity feed (the same
 * rows, filtered to mutating methods and labeled by route).
 */
class ApiRequestLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'actor_role', 'method', 'path', 'route_name',
        'status_code', 'duration_ms', 'ip', 'user_agent',
        'request_summary', 'response_summary', 'is_error', 'created_at',
    ];

    protected $casts = [
        'is_error'   => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
