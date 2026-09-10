<?php

namespace App\Modules\Auth\Support;

use App\Models\Company;
use App\Modules\Catalog\IndustryTemplates;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaChat\Models\AgentPlaybookTemplate;
use App\Modules\WaChat\Models\AiKnowledgeBase;
use App\Modules\WaChat\Models\AiPipeline;
use Illuminate\Support\Facades\Log;

/**
 * Seeds a new company with editable *sample* data so the AI Agent screens aren't
 * empty on day one — a starter playbook, one FAQ doc and one pipeline. Everything
 * is inactive and clearly labelled "Sample", so a company deletes it and adds its
 * own once it's ready. Idempotent: skips anything the company already has.
 */
class CompanyStarterKit
{
    /** industry_templates key  ->  AgentPlaybookTemplate key */
    private const PLAYBOOK_TEMPLATE_MAP = [
        'real_estate'   => 'real_estate',
        'health_clinic' => 'services',
        'education'     => 'lms',
        'generic'       => 'services',
    ];

    public function seed(Company $company, string $businessType = 'generic'): void
    {
        try {
            $this->seedPlaybook($company, $businessType);
            $this->seedKnowledgeBase($company, $businessType);
            $this->seedPipeline($company);
        } catch (\Throwable $e) {
            // Never let sample seeding break registration.
            Log::warning("CompanyStarterKit: seed failed for company {$company->id}: " . $e->getMessage());
        }
    }

    private function seedPlaybook(Company $company, string $businessType): void
    {
        if (AgentPlaybook::where('company_id', $company->id)->exists()) {
            return;
        }

        $templateKey = self::PLAYBOOK_TEMPLATE_MAP[$businessType] ?? 'services';
        $template    = AgentPlaybookTemplate::where('key', $templateKey)->first();

        $attrs = $template
            ? $template->toPlaybookAttributes()
            : [
                'agent_name'    => 'Assistant',
                'greeting_new'  => "Hi! 👋 How can I help you today?",
                'system_prompt' => 'You are a helpful assistant for this business.',
                'qualification_questions' => [
                    ['key' => 'name',  'question' => 'May I have your name?',  'required' => true],
                    ['key' => 'phone', 'question' => 'And a phone number we can reach you on?', 'required' => true],
                ],
            ];

        AgentPlaybook::create(array_merge($attrs, [
            'company_id'   => $company->id,
            'business_type'=> $businessType,
            'agent_name'   => $attrs['agent_name'] ?? 'Assistant',
            'is_active'    => false,
            'meta'         => array_merge($attrs['meta'] ?? [], ['is_sample' => true]),
        ]));
    }

    private function seedKnowledgeBase(Company $company, string $businessType): void
    {
        if (AiKnowledgeBase::where('company_id', $company->id)->exists()) {
            return;
        }

        $name = IndustryTemplates::get($businessType)['name'] ?? 'your business';

        AiKnowledgeBase::create([
            'company_id'    => $company->id,
            'name'          => 'Sample FAQ — replace with your own',
            'description'   => 'Starter content. Delete this and add your real FAQs, pricing and policies so the AI answers accurately.',
            'document_type' => 'text',
            // Left un-indexed (no embed job dispatched) — the agent ignores it until
            // you open it and click re-index, or delete it and add your own.
            'status'        => 'pending',
            'raw_content'   => implode("\n\n", [
                "About us: We are a {$name} business. (Replace this with a short description.)",
                "Working hours: Monday–Saturday, 9am–7pm.",
                "Location: Add your address here.",
                "Pricing: Add your prices / packages here so the agent can quote them.",
                "How to book / enquire: Add the steps a customer follows.",
            ]),
        ]);
    }

    private function seedPipeline(Company $company): void
    {
        if (AiPipeline::where('company_id', $company->id)->exists()) {
            return;
        }

        AiPipeline::create([
            'company_id'   => $company->id,
            'name'         => 'Sample: welcome & qualify new leads',
            'description'  => 'Starter automation. Turn it on once you have reviewed the steps, or delete it and build your own.',
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'steps'        => [
                ['type' => 'send_message', 'config' => ['text' => "Thanks for reaching out! A team member will be with you shortly."]],
                ['type' => 'run_agent',    'config' => []],
            ],
            'is_active'    => false,
        ]);
    }
}
