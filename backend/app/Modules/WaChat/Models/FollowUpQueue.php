<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUpQueue extends Model
{
    protected $table = 'follow_up_queue';

    protected $fillable = [
        'company_id', 'rule_id', 'session_id', 'contact_phone',
        'contact_name', 'message_payload', 'scheduled_at',
        'executed_at', 'status', 'error_message',
    ];

    protected $casts = [
        'message_payload' => 'array',
        'scheduled_at'    => 'datetime',
        'executed_at'     => 'datetime',
    ];
}
