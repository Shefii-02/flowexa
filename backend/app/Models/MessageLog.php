<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// MessageLog
// ════════════════════════════════════════════════════════════════════════════
class MessageLog extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'wa_message_id', 'direction',
        'type', 'phone', 'content', 'status', 'cost', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'content'      => 'array',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }

    public function scopeInbound($q)  { return $q->where('direction', 'inbound'); }
    public function scopeOutbound($q) { return $q->where('direction', 'outbound'); }
    public function scopeDelivered($q){ return $q->where('status', 'delivered'); }
    public function scopeRead($q)     { return $q->where('status', 'read'); }
}
