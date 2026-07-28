<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

//
// ════════════════════════════════════════════════════════════════════════════
// WebhookLog
// ════════════════════════════════════════════════════════════════════════════
class WebhookLog extends Model
{
    protected $fillable = ['company_id', 'payload', 'status', 'error', 'processing_ms'];

    protected $casts = ['payload' => 'array'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
