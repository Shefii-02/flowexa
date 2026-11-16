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
        'steps', 'escalate_keywords',
    ];

    protected $casts = [
        'schedule_days' => 'array',
        'handover'      => 'array',
        'steps'         => 'array',
        'escalate_keywords' => 'array',
        'is_active'     => 'boolean',
        'priority'      => 'integer',
    ];

    public const STEP_TYPES = ['chatbot', 'ai_agent', 'handover', 'auto_reply', 'manual'];

    /**
     * The responder chain, in order. New routes store `steps`; an older single-type route (response_type + optional
     * fallback) is presented as the equivalent one- or two-step chain so both behave the same way.
     *
     * @return array<int, array<string,mixed>>
     */
    public function resolvedSteps(): array
    {
        if (!empty($this->steps)) {
            return array_values($this->steps);
        }
        $steps = [[
            'type' => $this->response_type, 'flow_builder_id' => $this->flow_builder_id, 'playbook_id' => $this->playbook_id,
            'handover' => $this->handover, 'reply_text' => $this->reply_text,
            'schedule' => ['mode' => $this->when_mode ?: 'always', 'days' => $this->schedule_days, 'start' => $this->schedule_start, 'end' => $this->schedule_end],
        ]];
        if ($this->fallback_type === 'handover') {
            $steps[] = ['type' => 'handover', 'handover' => $this->handover, 'schedule' => ['mode' => 'always']];
        } elseif ($this->fallback_type === 'auto_reply') {
            $steps[] = ['type' => 'auto_reply', 'reply_text' => $this->reply_text, 'schedule' => ['mode' => 'always']];
        }
        return $steps;
    }
}
