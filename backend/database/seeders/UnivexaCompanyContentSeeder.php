<?php

namespace Database\Seeders;

use App\Modules\WaChat\Jobs\GenerateKnowledgeEmbeddings;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaChat\Models\AiKnowledgeBase;
use App\Modules\WaChat\Models\AiPipeline;
use Illuminate\Database\Seeder;

/**
 * Demo content for company #6 (Univexa Technologies Pvt Ltd) — a custom
 * software / SaaS development company. Not part of DatabaseSeeder's global
 * run (like LeadCategorySeeder, this is a one-off for a specific company):
 *
 *   php artisan db:seed --class=UnivexaCompanyContentSeeder
 *
 * Idempotent — matches on name (knowledge base docs, pipeline) or the
 * company-wide slot (playbook with session_id = null), so re-running updates
 * rather than duplicates.
 */
class UnivexaCompanyContentSeeder extends Seeder
{
    private const COMPANY_ID = 6;

    public function run(): void
    {
        $this->seedKnowledgeBase();
        $this->seedPlaybook();
        $this->seedPipeline();
    }

    private function seedKnowledgeBase(): void
    {
        $docs = [
            [
                'name' => 'About Univexa Technologies',
                'description' => 'Company overview',
                'raw_content' => <<<TXT
Univexa Technologies Pvt Ltd is a custom software and SaaS development company. We design, build, and maintain
software products for businesses that need something off-the-shelf tools can't handle — from internal
operations tools to full multi-tenant SaaS platforms. Our own product, Flowexa, is a multi-tenant WhatsApp
business automation and CRM platform that we built and run ourselves, so we understand SaaS from both sides:
as builders and as operators.

We work with startups building their first product, and with established businesses replacing spreadsheets
and disconnected tools with a proper custom system. Every engagement starts with understanding the actual
business problem before writing a line of code.
TXT,
            ],
            [
                'name' => 'Services We Offer',
                'description' => 'Service catalogue',
                'raw_content' => <<<TXT
Our services:

1. Custom software development — bespoke internal tools, dashboards, and business systems built around how
   your team actually works, not the other way around.
2. SaaS product development — end-to-end: multi-tenant architecture, billing/subscriptions, role-based
   permissions, and the operational tooling (admin panels, analytics) a real SaaS product needs to run.
3. WhatsApp & AI automation — conversational AI agents, WhatsApp Business API / Cloud API integration,
   lead capture and CRM automation (this is what our own product, Flowexa, is built on).
4. Web & mobile applications — customer-facing apps and portals, built with modern frameworks.
5. API integrations — connecting your existing tools (payments, CRMs, ERPs) into one working system.
6. Ongoing maintenance & support — we don't disappear after launch; we offer retainer support for
   bug fixes, feature additions, and scaling as your usage grows.
TXT,
            ],
            [
                'name' => 'Engagement Process & Pricing',
                'description' => 'How we work and price projects',
                'raw_content' => <<<TXT
How we work:

Step 1 — Discovery call: a free initial call to understand your requirements, current pain points, and goals.
Step 2 — Proposal: we send a scope of work with a fixed-price quote (for well-defined projects) or a
monthly retainer estimate (for ongoing/evolving work).
Step 3 — Build: we work in short iterations and share progress regularly rather than disappearing for months.
Step 4 — Launch & support: after launch we offer a support window, and ongoing retainer support afterward.

Pricing depends on scope and complexity — a small internal tool is very different from a full multi-tenant
SaaS platform. We quote per-project after the discovery call rather than a flat rate card, so every quote is
based on your actual requirements. Typical timelines range from 2-4 weeks for a focused tool to 3+ months for
a full SaaS platform build.
TXT,
            ],
            [
                'name' => 'Tech Stack',
                'description' => 'Technologies we build with',
                'raw_content' => <<<TXT
Our primary stack: Laravel (PHP) and Node.js on the backend, React with TypeScript on the frontend, MySQL /
PostgreSQL for data, Redis for queues and caching, and we integrate LLM providers (Anthropic Claude, OpenAI,
Google Gemini) for AI features like conversational agents and RAG-based knowledge search. We deploy on
standard cloud infrastructure (VPS/cloud providers) with CI-friendly, containerizable setups. We pick the
stack to fit the project, but this is what we default to and know best.
TXT,
            ],
        ];

        foreach ($docs as $doc) {
            $kb = AiKnowledgeBase::updateOrCreate(
                ['company_id' => self::COMPANY_ID, 'name' => $doc['name']],
                [
                    'description'   => $doc['description'],
                    'document_type' => 'text',
                    'raw_content'   => $doc['raw_content'],
                    'status'        => 'pending',
                ]
            );

            // Sync (not queue) so the chunks + TF-IDF vectors exist immediately —
            // a seeder shouldn't depend on a queue worker being up to take effect.
            GenerateKnowledgeEmbeddings::dispatchSync($kb->id);

            $count = $kb->fresh()->chunk_count;
            $this->command?->info("  └─ KB '{$doc['name']}': {$count} chunk(s) indexed.");
        }
    }

    private function seedPlaybook(): void
    {
        AgentPlaybook::updateOrCreate(
            ['company_id' => self::COMPANY_ID, 'session_id' => null],
            [
                'template_key'   => 'custom_software_saas',
                'business_type'  => 'software_saas',
                'is_active'      => true,
                'agent_name'     => 'Univexa Assistant',
                'tone'           => 'professional',
                'languages'      => ['en'],
                'system_prompt'  => 'You are the AI assistant for Univexa Technologies Pvt Ltd, a custom software '
                    . 'and SaaS development company. Answer questions about our services (custom software, SaaS '
                    . 'product development, WhatsApp/AI automation, web & mobile apps, API integrations, '
                    . 'maintenance & support), our process, and pricing approach, using only the knowledge base '
                    . 'provided. Be concise and professional. If you do not know something specific, say so '
                    . 'honestly and offer to connect the person with the team rather than guessing.',
                'greeting_new'       => "👋 Hi! Welcome to Univexa Technologies — we build custom software and SaaS products. How can I help you today?",
                'greeting_returning' => 'Welcome back! How can I help you today?',
                'closing_message'    => 'Thanks for reaching out to Univexa Technologies — our team will follow up shortly.',
                'fallback_transfer_message' => "I'll connect you with our team for a detailed answer on that.",
                'qualification_questions' => [
                    ['key' => 'project_type', 'question' => 'What type of project are you looking for — custom software, a SaaS product, or something else?', 'required' => true],
                    ['key' => 'budget_range', 'question' => "What's your approximate budget range?", 'required' => false],
                    ['key' => 'timeline', 'question' => "What's your ideal timeline to get started?", 'required' => false],
                ],
                'handoff'    => ['enabled' => true, 'notify_staff' => true],
                'escalation' => ['enabled' => true, 'keywords' => ['human', 'agent', 'talk to someone', 'representative']],
                'payment'    => [],
                'meta'       => ['seeded_by' => 'UnivexaCompanyContentSeeder'],
            ]
        );

        $this->command?->info('  └─ Playbook: company-wide default set for Univexa Technologies.');
    }

    private function seedPipeline(): void
    {
        // is_active: false — illustrative/reference pipeline, not wired to a real
        // trigger or a live session, so seeding it can never send a real WhatsApp message.
        AiPipeline::updateOrCreate(
            ['company_id' => self::COMPANY_ID, 'name' => 'New Lead Welcome Sequence'],
            [
                'description'    => 'Example: acknowledge a new lead, answer with RAG if possible, otherwise hand off. '
                    . 'Inactive — wire a real session_id and trigger before enabling.',
                'trigger_type'   => 'manual',
                'trigger_config' => [],
                'is_active'      => false,
                'steps' => [
                    ['type' => 'rag_query', 'query' => '{{message}}'],
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => '{{rag_response}}'],
                ],
            ]
        );

        $this->command?->info('  └─ Pipeline: "New Lead Welcome Sequence" seeded (inactive).');
    }
}
