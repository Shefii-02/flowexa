<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// LeadEvent
// ════════════════════════════════════════════════════════════════════════════
class LeadEvent extends Model
{
    protected $fillable = ['lead_id', 'company_id', 'user_id', 'event', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function scopeNotes($q) { return $q->where('event', 'note_added'); }
}
