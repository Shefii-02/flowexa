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
        'company_id', 'session_name', 'display_name', 'requested_name', 'phone',
        'status', 'webhook_url', 'engine', 'last_seen_at',
        'gateway_created_at', 'session_token',
    ];

    protected $casts = [
        'last_seen_at'       => 'datetime',
        'gateway_created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(WahaWebhook::class, 'session_id');
    }

    /**
     * `status` is a strict MySQL ENUM('stopped','starting','qr','connected','disconnected'), but
     * the gateway reports its own, wider vocabulary (backend-node's SessionStatus enum, plus a
     * few legacy names the health-check poll still sees from older gateway responses). Writing
     * one of those raw — 'ready', 'qr_ready', 'authenticating', 'working', … — straight into the
     * column throws a truncation error at the DB layer instead of updating the row, which is why
     * live status pushes were silently failing. Every write to this column must go through here
     * first; already-valid enum values pass through unchanged.
     */
    public static function mapGatewayStatus(?string $raw): string
    {
        return match (strtolower((string) $raw)) {
            'ready', 'connected', 'working', 'authenticated' => 'connected',
            'qr_ready', 'qr' => 'qr',
            'initializing', 'authenticating', 'starting' => 'starting',
            'created', 'stopped' => 'stopped',
            default => 'disconnected', // disconnected, action_required, failed, unknown, …
        };
    }
}
