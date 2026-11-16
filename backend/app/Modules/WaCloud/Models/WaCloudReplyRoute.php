<?php

namespace App\Modules\WaCloud\Models;

use Illuminate\Database\Eloquent\Model;

/** @see database/migrations/2026_11_18_000001_create_wa_cloud_reply_routing_tables.php */
class WaCloudReplyRoute extends Model
{
    protected $table = 'wa_cloud_reply_routes';

    public const RESPONSE_TYPES = ['chatbot', 'ai_agent', 'handover', 'auto_reply', 'manual'];
    public const FALLBACKS = ['none', 'handover', 'auto_reply'];
    public const WHEN = ['always', 'in_hours', 'out_of_hours'];

    protected $fillable = [
        'company_id', 'wa_phone_number_id', 'name', 'when_mode', 'schedule_days', 'schedule_start', 'schedule_end',
        'response_type', 'flow_builder_id', 'playbook_id', 'handover', 'reply_text', 'fallback_type', 'priority', 'is_active',
    ];

    protected $casts = [
        'schedule_days' => 'array',
        'handover'      => 'array',
        'is_active'     => 'boolean',
        'priority'      => 'integer',
    ];
}
