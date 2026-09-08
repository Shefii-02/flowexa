<?php

namespace App\Modules\WaChat\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's live agent configuration. One row per company (optionally one per
 * WhatsApp session). Drives greeting, qualification slot-filling, RAG persona,
 * lead handoff and the end-of-chat payment step.
 */
class AgentPlaybook extends Model
{
    protected $fillable = [
        'company_id', 'session_id', 'template_key', 'business_type', 'is_active',
        'agent_name', 'tone', 'languages', 'system_prompt',
        'greeting_new', 'greeting_returning', 'closing_message', 'fallback_transfer_message',
        'qualification_questions', 'handoff', 'escalation', 'payment', 'meta',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'languages'               => 'array',
        'qualification_questions' => 'array',
        'handoff'                 => 'array',
        'escalation'              => 'array',
        'payment'                 => 'array',
        'meta'                    => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The playbook that governs a given company + session: an exact session
     * match wins over the company-wide (session_id = null) default.
     */
    public static function resolveFor(int $companyId, ?string $sessionId): ?self
    {
        return static::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($sessionId) {
                $q->whereNull('session_id');
                if ($sessionId) {
                    $q->orWhere('session_id', $sessionId);
                }
            })
            ->orderByRaw('session_id IS NULL')   // non-null (specific) first
            ->first();
    }

    /** Required question keys not yet present in the collected slots. */
    public function missingRequiredSlots(array $slots): array
    {
        $missing = [];

        foreach ($this->qualification_questions ?? [] as $q) {
            $key = $q['key'] ?? null;
            if (!$key) {
                continue;
            }
            if (($q['required'] ?? true) && !array_key_exists($key, $slots)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
