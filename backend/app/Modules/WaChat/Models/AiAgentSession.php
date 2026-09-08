<?php

namespace App\Modules\WaChat\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentSession extends Model
{
    protected $table = 'ai_agent_sessions';

    protected $fillable = [
        'company_id', 'playbook_id', 'waha_session_id', 'contact_phone',
        'conversation_history', 'current_intent', 'context',
        'ai_config', 'last_message_at', 'status',
        'collected_slots', 'qualification_status', 'pending_question_key',
        'detected_language', 'detected_style',
    ];

    protected $casts = [
        'conversation_history' => 'array',
        'context'              => 'array',
        'ai_config'            => 'array',
        'collected_slots'      => 'array',
        'last_message_at'      => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(AgentPlaybook::class, 'playbook_id');
    }
}
