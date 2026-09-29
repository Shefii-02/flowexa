<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\WaChat\Models\AiAgentSession;

class LeadAssignment extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'lead_id', 'staff_id', 'ai_agent_session_id', 'campaign_id',
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
    public function lead(): BelongsTo           { return $this->belongsTo(Lead::class); }
    public function staff(): BelongsTo          { return $this->belongsTo(User::class, 'staff_id'); }
    public function campaign(): BelongsTo       { return $this->belongsTo(Campaign::class); }
    public function transferredFrom(): BelongsTo{ return $this->belongsTo(User::class, 'transferred_from'); }
    public function aiSession(): BelongsTo      { return $this->belongsTo(AiAgentSession::class, 'ai_agent_session_id'); }
    public function notifications(): HasMany    { return $this->hasMany(LeadAssignmentNotification::class, 'assignment_id'); }

    public function isPending(): bool    { return $this->status === 'pending'; }
    public function isAssigned(): bool   { return in_array($this->status, ['assigned', 'accepted']); }
    public function isAiHandling(): bool { return $this->status === 'ai_handling'; }
    public function isCompleted(): bool  { return $this->status === 'completed'; }

    /**
     * `source_type` is a strict MySQL ENUM (see the widen-source-type migration), but `Lead::source`
     * is drawn from the much wider `config('lead_sources')` vocabulary (see that config file) —
     * callers that pass a lead's raw `source` straight through as `sourceType` (e.g.
     * ProcessUnassignedLeads) throw a truncation error at the DB layer for any value outside the
     * enum, such as 'flow', instead of creating the assignment. Mirrors WahaSession::mapGatewayStatus
     * for the same reason: every write to this column must go through here first; already-valid
     * enum values pass through unchanged.
     */
    public static function mapLeadSource(?string $source): string
    {
        return match ((string) $source) {
            'wa_chat' => 'wa_chat',
            'website', 'website_widget' => 'website_widget',
            'instagram', 'instagram_dm' => 'instagram',
            'whatsapp', 'whatsapp_ai' => 'whatsapp',
            'whatsapp_cloud' => 'meta_api',
            'flow', 'survey_form' => 'flow_builder',
            'campaign', 'meta_ads', 'google_ads' => 'campaign',
            'manual' => 'manual',
            default => 'organic', // referral, walk_in, import, api, organic, unknown, …
        };
    }
}
