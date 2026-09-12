<?php

namespace App\Modules\Catalog;

use App\Models\Lead;
use App\Models\WidgetConversation;

/**
 * Decides when a conversation has enough information to be a real lead. The "make it a routed CRM
 * lead" work (Contact, Lead, staff assignment, notifications, SLA follow-up) is delegated to
 * {@see LeadIntake} so every channel goes through the same pipeline. Vertical-agnostic — the
 * required fields come from the industry template.
 */
class LeadQualifier
{
    public function __construct(private readonly LeadIntake $intake) {}

    /** @return array{qualified:bool, missing:array<string>, have:array<string>} */
    public function evaluate(string $industryTemplate, array $collected): array
    {
        $required = collect(IndustryTemplates::get($industryTemplate)['qualification_fields'] ?? [])
            ->filter(fn ($f) => !empty($f['required']))
            ->pluck('key');

        $have    = $required->filter(fn ($k) => filled($collected[$k] ?? null))->values();
        $missing = $required->reject(fn ($k) => filled($collected[$k] ?? null))->values();

        return [
            'qualified' => $missing->isEmpty(),
            'missing'   => $missing->all(),
            'have'      => $have->all(),
        ];
    }

    /**
     * Qualify a widget conversation if it now has everything, creating a Contact + Lead. Returns the
     * Lead when it was just created (so the caller can fire alerts), null otherwise.
     */
    public function qualifyWidgetConversation(WidgetConversation $convo, string $industryTemplate): ?Lead
    {
        if ($convo->status === 'qualified') {
            return null;
        }

        $collected = $convo->collected ?? [];
        if (!$this->evaluate($industryTemplate, $collected)['qualified']) {
            return null;
        }

        $fields = array_merge($collected, array_filter([
            'name'  => $collected['name']  ?? $convo->visitor_name,
            'email' => $collected['email'] ?? $convo->visitor_email,
            'phone' => $collected['phone'] ?? $convo->visitor_phone,
        ]));

        // A company can run several widgets (different site pages) — record which one.
        $widget = $convo->relationLoaded('widget') ? $convo->widget : $convo->widget()->first();
        $result = $this->intake->capture(
            $convo->company, $fields, 'website_widget', "widget:{$convo->id}",
            originType: $widget ? 'widget' : null,
            originId: $widget?->id,
            originLabel: $widget?->name,
        );

        $convo->update([
            'status'        => 'qualified',
            'qualified_at'  => now(),
            'visitor_name'  => $fields['name'] ?? $convo->visitor_name,
            'visitor_phone' => $result['contact']?->phone ?? $convo->visitor_phone,
            'visitor_email' => $fields['email'] ?? $convo->visitor_email,
            'contact_id'    => $result['contact']?->id,
            'lead_id'       => $result['lead']?->id,
        ]);

        return $result['lead'];
    }
}
