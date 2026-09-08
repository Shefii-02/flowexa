<?php

namespace App\Modules\WaCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaCloudAutomationLog extends Model
{
    protected $table = 'wa_cloud_automation_logs';

    protected $fillable = [
        'company_id', 'rule_id', 'wa_phone_number_id', 'contact_phone',
        'rule_type', 'trigger_data', 'action_taken', 'result',
        'status', 'error_message',
    ];

    protected $casts = [
        'trigger_data' => 'array',
        'result'       => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WaCloudAutomationRule::class, 'rule_id');
    }
}
