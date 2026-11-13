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
        'company_id', 'session_id', 'template_key', 'business_type', 'function_key', 'channels', 'abilities', 'is_active',
        'agent_name', 'tone', 'languages', 'system_prompt',
        'greeting_new', 'greeting_returning', 'closing_message', 'fallback_transfer_message',
        'qualification_questions', 'handoff', 'escalation', 'payment', 'meta',
        'ai_schedule_mode', 'ai_schedule_days', 'ai_schedule_start', 'ai_schedule_end', 'ai_schedule_timezone',
        'ai_schedule_hours',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'channels'                => 'array',
        'abilities'               => 'array',
        'languages'               => 'array',
        'qualification_questions' => 'array',
        'handoff'                 => 'array',
        'escalation'              => 'array',
        'payment'                 => 'array',
        'meta'                    => 'array',
        'ai_schedule_days'        => 'array',
        'ai_schedule_hours'       => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** What an agent can be dedicated to. The text steers the prompt so a sales agent stays on sales, an HR agent on HR … */
    public const FUNCTIONS = [
        'general' => ['General', null],
        'sales'   => ['Sales', 'You are a SALES agent. Handle product, pricing, offers, demos and buying questions, and move interested people towards a purchase or a sales call. For anything unrelated to sales, say you will pass it to the right team.'],
        'support' => ['Support', 'You are a SUPPORT agent. Handle help, troubleshooting, order and account questions. Stay on support topics; for sales or other topics, say you will pass it to the right team.'],
        'hr'      => ['HR', 'You are an HR agent. Handle hiring, jobs, attendance, leave, payroll and staff questions only. For anything else, say you will pass it to the right team.'],
        'finance' => ['Finance', 'You are a FINANCE agent. Handle invoices, payments, dues and receipts only. For anything else, say you will pass it to the right team.'],
        'custom'  => ['Custom', null],
    ];
    public const CHANNELS = ['wa_chat', 'wa_cloud', 'instagram', 'widget'];

    /**
     * The jobs an agent may do. Each is switched per agent, so a support agent can answer and book follow-ups without ever
     * touching leads. key => [label, description, plan module it needs (or null)].
     */
    public const ABILITIES = [
        'answer_questions' => ['Answer questions', 'Reply from the knowledge base and the playbook', null],
        'qualify'          => ['Ask qualification questions', 'Collect the playbook\'s questions one by one', null],
        'leads'            => ['Create & update leads', 'Open a lead for new conversations and update its stage when qualified', 'leads'],
        'lead_scoring'     => ['Score leads', 'Rate sentiment and buying intent after a conversation', 'leads'],
        'followup_tasks'   => ['Schedule follow-ups', 'Create CRM follow-up tasks for staff', 'crm'],
    ];

    public static function defaultAbilities(?string $function): array
    {
        return match ($function) {
            'sales'   => ['answer_questions', 'qualify', 'leads', 'lead_scoring', 'followup_tasks'],
            'support' => ['answer_questions', 'followup_tasks'],
            'hr', 'finance', 'custom' => ['answer_questions'],
            default   => ['answer_questions', 'qualify', 'leads', 'lead_scoring', 'followup_tasks'],   // general = how every agent behaved before
        };
    }

    /** May this agent do this job? It must be switched on for the agent AND the company's plan must include the module the job needs. */
    public function can(string $ability): bool
    {
        $on = $this->abilities ?? self::defaultAbilities($this->function_key);
        if (!in_array($ability, $on, true)) { return false; }
        $module = self::ABILITIES[$ability][2] ?? null;
        return !$module || \App\Support\Entitlements::has(\App\Models\Company::find($this->company_id), $module);
    }

    /** Knowledge documents this agent may read: its own plus the ones shared with every agent. */
    public function knowledgeBaseIds(): array
    {
        return \App\Modules\WaChat\Models\AiKnowledgeBase::where('company_id', $this->company_id)
            ->where(fn ($q) => $q->whereNull('agent_id')->orWhere('agent_id', $this->id))
            ->pluck('id')->all();
    }

    /** Extra prompt line for this agent's function (null for general/custom). */
    public function focusInstruction(): ?string
    {
        return self::FUNCTIONS[$this->function_key ?? 'general'][1] ?? null;
    }

    /** Does this agent answer on the given channel? (no channel list = all channels) */
    public function coversChannel(?string $channel): bool
    {
        return !$channel || empty($this->channels) || in_array($channel, $this->channels, true);
    }

    /**
     * The ACTIVE agent that governs a company + session (+ channel). A paused agent is never picked, so switching an
     * agent off hands its conversations to the next agent that covers them — or to nobody. Order of preference:
     * a number/session-specific agent, then one dedicated to this channel, then the company-wide one.
     */
    public static function resolveFor(int $companyId, ?string $sessionId, ?string $channel = null): ?self
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
            ->orderBy('id')
            ->get()
            ->filter(fn (self $p) => $p->coversChannel($channel))
            ->sortBy(fn (self $p) => [$p->session_id ? 0 : 1, empty($p->channels) ? 1 : 0])   // specific session > channel-specific > all-channels
            ->first();
    }

    /** Required question keys not yet present in the collected slots. */
    public function missingRequiredSlots(array $slots): array
    {
        $missing = [];
        if (!$this->can('qualify')) { return $missing; }

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
