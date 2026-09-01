<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;

class AiAgentSession extends Model
{
    protected $table = 'ai_agent_sessions';

    protected $fillable = [
        'company_id', 'waha_session_id', 'contact_phone',
        'conversation_history', 'current_intent', 'context',
        'ai_config', 'last_message_at', 'status',
    ];

    protected $casts = [
        'conversation_history' => 'array',
        'context'              => 'array',
        'ai_config'            => 'array',
        'last_message_at'      => 'datetime',
    ];
}
