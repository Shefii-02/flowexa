<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationLog extends Model
{
    protected $fillable = [
        'company_id', 'rule_id', 'session_id', 'contact_phone',
        'rule_type', 'trigger_data', 'action_taken', 'result',
        'status', 'error_message',
    ];

    protected $casts = [
        'trigger_data' => 'array',
        'result'       => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}
