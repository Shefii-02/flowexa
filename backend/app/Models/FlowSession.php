<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// FlowSession
// ════════════════════════════════════════════════════════════════════════════
class FlowSession extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'current_node_id', 'phone', 'context', 'expires_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'expires_at' => 'datetime',
    ];

    public function company(): BelongsTo     { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo     { return $this->belongsTo(Contact::class); }
    public function currentNode(): BelongsTo { return $this->belongsTo(FlowNode::class, 'current_node_id'); }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
