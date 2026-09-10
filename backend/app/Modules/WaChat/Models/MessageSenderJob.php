<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Company;
use App\Models\User;

class MessageSenderJob extends Model
{
    protected $table = 'message_sender_jobs';

    protected $fillable = [
        'company_id', 'created_by', 'campaign_name', 'session_id',
        'type', 'status', 'total', 'sent', 'failed',
        'scheduled_at', 'started_at', 'completed_at',
        'delay_ms', 'unique_signature', 'log', 'message_payload',
    ];

    protected $casts = [
        'log'              => 'array',
        'message_payload'  => 'array',
        'unique_signature' => 'boolean',
        'scheduled_at'     => 'datetime',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    protected $appends = ['session_name'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function messageLogs(): HasMany { return $this->hasMany(WahaMessageLog::class, 'job_id'); }

    /**
     * The WhatsApp session this job was sent through. `session_id` stores the
     * gateway session id, which is persisted locally as waha_sessions.session_name.
     */
    public function wahaSession(): BelongsTo
    {
        return $this->belongsTo(WahaSession::class, 'session_id', 'session_name');
    }

    /**
     * Human-readable session label for the campaign-history UI. Falls back to the
     * raw id when the session has no display name or has since been deleted.
     */
    public function getSessionNameAttribute(): ?string
    {
        if (!$this->session_id) {
            return null;
        }

        $session = $this->relationLoaded('wahaSession')
            ? $this->getRelation('wahaSession')
            : $this->wahaSession()->first();

        return $session?->display_name ?: $this->session_id;
    }
}
