<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\WaChat\Models\AiAgentSession;

class LeadAssignment extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'staff_id', 'ai_agent_session_id', 'campaign_id',
        'source_type', 'source_ref', 'status', 'assignment_type', 'priority',
        'accepted_at', 'first_reply_at', 'response_sla_minutes',
        'sla_breached', 'sla_breached_at',
        'ai_takeover_at', 'ai_offered_at', 'staff_confirmed_at',
        'transfer_reason', 'transferred_from', 'notes',
    ];

    protected $casts = [
        'sla_breached'       => 'boolean',
        'accepted_at'        => 'datetime',
        'first_reply_at'     => 'datetime',
        'sla_breached_at'    => 'datetime',
        'ai_takeover_at'     => 'datetime',
        'ai_offered_at'      => 'datetime',
        'staff_confirmed_at' => 'datetime',
    ];

    public function company(): BelongsTo        { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo        { return $this->belongsTo(Contact::class); }
    public function staff(): BelongsTo          { return $this->belongsTo(User::class, 'staff_id'); }
    public function campaign(): BelongsTo       { return $this->belongsTo(Campaign::class); }
    public function transferredFrom(): BelongsTo{ return $this->belongsTo(User::class, 'transferred_from'); }
    public function aiSession(): BelongsTo      { return $this->belongsTo(AiAgentSession::class, 'ai_agent_session_id'); }
    public function notifications(): HasMany    { return $this->hasMany(LeadAssignmentNotification::class, 'assignment_id'); }

    public function isPending(): bool    { return $this->status === 'pending'; }
    public function isAssigned(): bool   { return in_array($this->status, ['assigned', 'accepted']); }
    public function isAiHandling(): bool { return $this->status === 'ai_handling'; }
    public function isCompleted(): bool  { return $this->status === 'completed'; }
}
