<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


// ════════════════════════════════════════════════════════════════════════════
// CampaignContact
// ════════════════════════════════════════════════════════════════════════════
class CampaignContact extends Model
{
    protected $fillable = [
        'campaign_id', 'contact_id', 'phone', 'status',
        'wa_message_id', 'failed_reason', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function contact(): BelongsTo  { return $this->belongsTo(Contact::class); }

    public function scopePending($q)   { return $q->where('status', 'pending'); }
    public function scopeFailed($q)    { return $q->where('status', 'failed'); }
    public function scopeDelivered($q) { return $q->where('status', 'delivered'); }
}
