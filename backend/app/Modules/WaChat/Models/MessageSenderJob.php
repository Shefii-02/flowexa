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

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function messageLogs(): HasMany { return $this->hasMany(WahaMessageLog::class, 'job_id'); }
}
