<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp Business Calling API call, assembled from the `calls` webhook
 * field. @see database/migrations/2026_09_08_000005_create_wa_calls_table.php
 */
class WaCall extends Model
{
    protected $table = 'wa_calls';

    protected $fillable = [
        'company_id', 'wa_call_id', 'phone_number_id', 'contact_id', 'conversation_id', 'assigned_to',
        'direction', 'status', 'from_phone', 'to_phone',
        'started_at', 'connected_at', 'ended_at', 'duration_seconds', 'raw',
    ];

    protected $casts = [
        'raw'              => 'array',
        'started_at'       => 'datetime',
        'connected_at'     => 'datetime',
        'ended_at'         => 'datetime',
        'duration_seconds' => 'integer',
    ];

    /** Statuses that count as a call that never connected. */
    public const UNANSWERED = ['missed', 'rejected', 'failed', 'no_answer', 'canceled'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
