<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


// ════════════════════════════════════════════════════════════════════════════
// CrmSyncOutbox
// ════════════════════════════════════════════════════════════════════════════
class CrmSyncOutbox extends Model
{
    protected $table    = 'crm_sync_outbox';
    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'event',
        'payload', 'status', 'error', 'attempts', 'sent_at',
    ];

    protected $casts = [
        'payload'  => 'array',
        'sent_at'  => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function scopePending($q) { return $q->where('status', 'pending'); }
    public function scopeFailed($q)  { return $q->where('status', 'failed'); }
}
