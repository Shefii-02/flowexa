<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A superadmin-owned category preset. Companies clone one of these into an
 * {@see AgentPlaybook} that they can then tweak.
 */
class AgentPlaybookTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'icon', 'is_active', 'sort_order', 'default_config',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'sort_order'     => 'integer',
        'default_config' => 'array',
    ];

    /** Fields copied verbatim from default_config into a new AgentPlaybook. */
    public const PLAYBOOK_FIELDS = [
        'agent_name', 'tone', 'languages', 'system_prompt',
        'greeting_new', 'greeting_returning', 'closing_message',
        'fallback_transfer_message', 'qualification_questions',
        'handoff', 'escalation', 'payment',
    ];

    public function toPlaybookAttributes(): array
    {
        $config = $this->default_config ?? [];
        $out    = ['template_key' => $this->key, 'business_type' => $this->key];

        foreach (self::PLAYBOOK_FIELDS as $field) {
            if (array_key_exists($field, $config)) {
                $out[$field] = $config[$field];
            }
        }

        return $out;
    }
}
