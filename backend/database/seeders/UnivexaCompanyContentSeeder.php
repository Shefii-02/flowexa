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
 * Idempotent — matches on name (knowledge base docs, pipelines) or a stable
 * slot (playbooks), so re-running updates rather than duplicates.
 *
 * This seeds 10 knowledge base documents, 10 pipelines, and 3 playbooks —
 * deliberately broad and varied so the RAG pipeline has real material to be
 * tested against: different topics, an informally-phrased FAQ doc (short,
 * casual, typo-ish customer phrasings deliberately baked into the source
 * text — since retrieval here is lexical/TF-IDF, not fuzzy, giving the
 * *content* more of the ways real customers actually phrase things is what
 * makes odd phrasing matchable, not a change to the matching algorithm
 * itself), and a multilingual doc covering the three scripts LanguageDetector
 * actually distinguishes (Arabic, Hindi, Chinese) so a query in one of those
 * languages has real content to retrieve, not just an English chunk the
 * model has to translate on the fly.
 */
class UnivexaCompanyContentSeeder extends Seeder
{
    private const COMPANY_ID = 6;

    public function run(): void
    {
        $this->seedKnowledgeBase();
        $this->seedPlaybooks();
        $this->seedPipelines();
    }

    private function seedKnowledgeBase(): void
    {
        // Superseded by the smaller, split "FAQ: ..." documents below (see the comment
        // above them) — remove the old single-blob doc a prior run of this seeder created,
        // so re-running never leaves stale, unreferenced content behind.
        AiKnowledgeBase::where('company_id', self::COMPANY_ID)
            ->where('name', 'Frequently Asked Questions')
            ->delete();

        $docs = [
            [
                'name' => 'About Univexa Technologies',
                'description' => 'Company overview',
                'raw_content' => <<<TXT
Univexa Technologies Pvt Ltd is a custom software and SaaS development company (also described as: a software
agency, a dev shop, a tech partner). We design, build, and maintain software products for businesses that need
something off-the-shelf tools can't handle — from internal operations tools to full multi-tenant SaaS
platforms. Our own product, Flowexa, is a multi-tenant WhatsApp business automation and CRM platform that we
built and run ourselves, so we understand SaaS from both sides: as builders and as operators.

We work with startups building their first product, and with established businesses replacing spreadsheets
and disconnected tools with a proper custom system. Every engagement starts with understanding the actual
business problem before writing a line of code. We are a small, senior team — no bloated account-management
layers, you talk directly to the people building your product.
TXT,
            ],
            [
                'name' => 'Services We Offer',
                'description' => 'Service catalogue',
                'raw_content' => <<<TXT
Our services:

1. Custom software development (a.k.a. bespoke software, custom apps, internal tools) — dashboards and
   business systems built around how your team actually works, not the other way around.
2. SaaS product development — end-to-end: multi-tenant architecture, billing/subscriptions, role-based
   permissions, and the operational tooling (admin panels, analytics) a real SaaS product needs to run.
3. WhatsApp & AI automation (a.k.a. WhatsApp bot, chatbot, AI agent, WA automation) — conversational AI
   agents, WhatsApp Business API / Cloud API integration, lead capture and CRM automation (this is what our
   own product, Flowexa, is built on).
4. Web & mobile applications — customer-facing apps and portals, built with modern frameworks.
5. API integrations — connecting your existing tools (payments, CRMs like Zoho or Salesforce, ERPs) into one
   working system.
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
a full SaaS platform build. We accept payment by milestone for fixed-price projects, and monthly billing for
retainers.
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
            [
                'name' => 'Industries We Serve',
                'description' => 'Sectors and example use cases',
                'raw_content' => <<<TXT
We've delivered projects across several industries:

- Healthcare & clinics — patient booking systems, appointment reminders over WhatsApp.
- Real estate — property CRMs, lead automation, and WhatsApp-based inquiry handling.
- E-commerce & retail — inventory systems, WhatsApp order bots, abandoned-cart follow-ups.
- Logistics & delivery — fleet tracking dashboards, driver/dispatch tools.
- Education — learning management systems (LMS), student enrollment portals.
- Professional services (agencies, consultancies) — client portals, proposal/invoicing tools.
- B2B SaaS — subscription billing systems, admin/ops tooling for other software companies.

If your industry isn't listed here, we still likely can help — we build custom software, not
industry-templated products, so the process adapts to your business rather than the other way around.
TXT,
            ],
            [
                'name' => 'Security, Privacy & Data Compliance',
                'description' => 'How we handle client data and security',
                'raw_content' => <<<TXT
All client data is stored on secure, access-controlled infrastructure. We use encrypted connections
(HTTPS/TLS) everywhere, encrypt sensitive fields such as API keys at rest, and follow role-based access
control so only authorized team members can access a given project's data.

For SaaS builds specifically, we implement proper multi-tenant data isolation so one client's data can never
leak into another's — this is core to how we build platforms like our own product, Flowexa.

We sign NDAs before development starts if requested, and can accommodate specific compliance requirements
(for example GDPR-style data handling, or data residency requests) — let us know your requirements during the
discovery call so we can confirm we can meet them before you commit.
TXT,
            ],
            [
                'name' => 'Support, Maintenance & SLAs',
                'description' => 'Post-launch support plans and response times',
                'raw_content' => <<<TXT
Every project includes a free bug-fix window immediately after launch. Beyond that, we offer monthly retainer
support plans covering: bug fixes, minor feature additions, server/infrastructure monitoring, and priority
WhatsApp/email support.

Typical response time for retainer clients: within 1 business day for non-critical issues, and same-day for
critical/production-down issues. Retainer plans are billed monthly and can be paused, scaled up, or scaled
down as your needs change — there's no long lock-in contract.

If something breaks and you're not on a retainer, we still respond — one-off support/fix requests are quoted
individually based on the issue.
TXT,
            ],
            [
                'name' => 'Onboarding & Project Kickoff Process',
                'description' => 'What happens after a proposal is accepted',
                'raw_content' => <<<TXT
Once a proposal is accepted, here's what happens:

1. Kickoff call — confirm scope, timeline, and preferred communication channel (WhatsApp, Slack, or email).
2. Project plan — we share milestones so you know what's being built and when.
3. Access handover — you provide any credentials/assets we need (hosting, domain, existing data/exports).
4. Development — work happens in short iterations with regular check-ins (weekly by default).
5. Milestone review — you review and approve each milestone before we move to the next, so there are no
   surprises at the end of the project.

This keeps you in control of the project throughout, rather than only seeing the result at the very end.
TXT,
            ],
            // The FAQ is deliberately split into several small, single-topic documents rather
            // than one big one — GenerateKnowledgeEmbeddings only splits a document into
            // multiple chunks past 400 words, so one giant FAQ blob becomes a single chunk
            // whose TF-IDF weight per term gets diluted across every unrelated Q&A pair in
            // it, which is exactly what made a short query like "wat do u guys do" score too
            // low to pass the relevance check despite the near-exact phrase being right there
            // in the content. Small, focused documents keep each one's own terms concentrated.
            [
                'name' => 'FAQ: What We Do & Pricing',
                'description' => 'Short, informally-phrased questions about services and cost',
                'raw_content' => <<<TXT
Q: wat do u guys do / what do you do?
A: We build custom software and SaaS products — internal tools, full SaaS platforms, WhatsApp/AI bots, web
and mobile apps, and API integrations. See "Services We Offer" for the full list.

Q: how much cost / how much does it cost / pricing?
A: Depends on the project — we quote after a free discovery call rather than a fixed rate card. Small tools
start around 2-4 weeks of work; full SaaS platforms are typically 3+ months.

Q: how long project take / timeline?
A: Typically 2-4 weeks for a focused internal tool, 3+ months for a full SaaS platform — it depends on scope.
TXT,
            ],
            [
                'name' => 'FAQ: WhatsApp Bot, Mobile Apps & Integrations',
                'description' => 'Short, informally-phrased questions about specific services',
                'raw_content' => <<<TXT
Q: u have whatsapp bot? / do you build chatbots?
A: Yes — WhatsApp & AI automation is one of our core services, including WhatsApp Business API / Cloud API
integration and conversational AI agents.

Q: do u do mobile apps?
A: Yes, web and mobile applications are one of our services.

Q: can u integrate zoho / salesforce / stripe?
A: Yes, API integrations connecting CRMs (Zoho, Salesforce), payment providers (like Stripe), and ERPs are
something we regularly do.

Q: wats ur tech stack / what technology do you use?
A: Mainly Laravel/Node.js on the backend, React + TypeScript on the frontend, MySQL/PostgreSQL, and we
integrate AI providers like Claude, OpenAI, and Gemini for AI features.
TXT,
            ],
            [
                'name' => 'FAQ: Security, Support & Working With Us',
                'description' => 'Short, informally-phrased questions about safety, support, and contracts',
                'raw_content' => <<<TXT
Q: is my data safe / is data secure?
A: Yes — encrypted connections, encrypted sensitive fields, role-based access control, and proper multi-tenant
data isolation for SaaS builds. See "Security, Privacy & Data Compliance" for details.

Q: do u offer support after launch / what happens after you finish?
A: Yes, we offer monthly retainer support plans for bug fixes, feature additions, and monitoring after launch.

Q: can i cancel anytime / is there a contract?
A: Retainer support plans have no long lock-in — they can be paused or scaled as needed.

Q: do u work with startups / small business?
A: Yes, we work with startups building their first product as well as established businesses.
TXT,
            ],
            [
                'name' => 'FAQ: Getting Started',
                'description' => 'Short, informally-phrased questions about location and next steps',
                'raw_content' => <<<TXT
Q: wheres ur office / location?
A: We work primarily remotely with clients, coordinating over WhatsApp, Slack, or email.

Q: how do i start / next steps?
A: Book a free discovery call so we can understand your requirements — from there we send a proposal.
TXT,
            ],
            [
                'name' => 'Multilingual Quick Facts',
                'description' => 'Company summary in Arabic, Hindi, and Chinese for non-English inquiries',
                'raw_content' => <<<TXT
[English] Univexa Technologies builds custom software and SaaS products, including WhatsApp/AI automation,
web and mobile apps, and API integrations. Contact us to book a free discovery call for pricing.

[العربية] شركة يونيفكسا للتقنية تقوم بتطوير برمجيات مخصصة ومنتجات SaaS، بما في ذلك أتمتة واتساب والذكاء
الاصطناعي، وتطبيقات الويب والجوال، وتكامل واجهات برمجة التطبيقات (API). تواصل معنا لحجز مكالمة استكشافية
مجانية لمعرفة الأسعار.

[हिन्दी] यूनिवेक्सा टेक्नोलॉजीज कस्टम सॉफ्टवेयर और SaaS उत्पाद बनाती है, जिसमें व्हाट्सएप/एआई ऑटोमेशन, वेब और
मोबाइल ऐप्स, और एपीआई इंटीग्रेशन शामिल हैं। कीमत जानने के लिए एक मुफ़्त डिस्कवरी कॉल बुक करने के लिए हमसे
संपर्क करें।

[中文] Univexa Technologies 专注于定制软件和 SaaS 产品开发，包括 WhatsApp/人工智能自动化、网页和移动应用程序开发，
以及 API 集成服务。请联系我们预约免费咨询电话以获取报价。
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

    private function seedPlaybooks(): void
    {
        // The real, live company-wide default — the only row that governs an actual
        // conversation unless a specific WA session has its own playbook (session_id
        // matching a real session, unique per company+session_id).
        AgentPlaybook::updateOrCreate(
            ['company_id' => self::COMPANY_ID, 'session_id' => null],
            [
                'template_key'   => 'custom_software_saas',
                'business_type'  => 'software_saas',
                'is_active'      => true,
                'agent_name'     => 'Univexa Assistant',
                'tone'           => 'professional',
                'languages'      => ['en', 'ar', 'hi', 'zh'],
                'system_prompt'  => 'You are the AI assistant for Univexa Technologies Pvt Ltd, a custom software '
                    . 'and SaaS development company. Answer questions about our services (custom software, SaaS '
                    . 'product development, WhatsApp/AI automation, web & mobile apps, API integrations, '
                    . 'maintenance & support), our process, and pricing approach, using only the knowledge base '
                    . 'provided. Be concise and professional. '
                    . 'Customers often write short, informal, or grammatically imperfect messages ("wat u guys do", '
                    . '"how much cost", "u have whatsapp bot") — treat these the same as a fully-formed question: '
                    . 'identify the most likely intent from the wording and the conversation so far, and answer it '
                    . 'directly rather than asking the customer to rephrase or correct their grammar. Customers may '
                    . 'also write in Arabic, Hindi, Chinese, or other languages — always reply in the same language '
                    . 'the customer used, even if the matched knowledge base content is in English. '
                    . 'If you do not know something specific, say so honestly and offer to connect the person with '
                    . 'the team rather than guessing.',
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
                'meta'       => ['seeded_by' => 'UnivexaCompanyContentSeeder', 'variant' => 'default'],
            ]
        );

        // Alternate variants — reference/example playbooks a company could later assign
        // to a specific WhatsApp session. They use a placeholder session_id (never a real
        // WA session id) purely so they occupy a different (company_id, session_id) slot
        // under the unique constraint on that pair — AgentPlaybook::resolveFor() only ever
        // matches a real session_id or the null company-wide default, so these can never be
        // picked up by an actual conversation while inactive/unassigned.
        $variants = [
            [
                'slot'  => '__template_sales_focused__',
                'agent_name' => 'Univexa Sales Assistant',
                'tone'       => 'persuasive',
                'system_prompt' => 'You are a sales-focused assistant for Univexa Technologies. Your goal is to '
                    . 'qualify the lead and move them toward booking a free discovery call. Answer questions from '
                    . 'the knowledge base, but always look for a natural moment to suggest booking a call. Handle '
                    . 'short/informal/multi-language messages the same as the default assistant does.',
                'qualification_questions' => [
                    ['key' => 'project_type',    'question' => 'What are you looking to build?', 'required' => true],
                    ['key' => 'decision_maker',  'question' => 'Are you the one making the final decision on this project?', 'required' => false],
                    ['key' => 'urgency',         'question' => 'How soon are you looking to get started?', 'required' => false],
                ],
                'meta' => ['variant' => 'sales_focused'],
            ],
            [
                'slot'  => '__template_support_focused__',
                'agent_name' => 'Univexa Support Assistant',
                'tone'       => 'calm and technical',
                'system_prompt' => 'You are a support-focused assistant for Univexa Technologies, helping existing '
                    . 'clients with issues, bugs, and maintenance requests rather than new sales inquiries. Use the '
                    . '"Support, Maintenance & SLAs" knowledge base content to set expectations on response time. '
                    . 'If the issue sounds critical/production-down, say you are escalating it immediately rather '
                    . 'than just answering generically.',
                'qualification_questions' => [
                    ['key' => 'issue_severity', 'question' => 'Is this affecting your live/production system right now?', 'required' => true],
                ],
                'escalation' => ['enabled' => true, 'keywords' => ['down', 'production', 'urgent', 'critical', 'not working']],
                'meta' => ['variant' => 'support_focused'],
            ],
        ];

        foreach ($variants as $v) {
            AgentPlaybook::updateOrCreate(
                ['company_id' => self::COMPANY_ID, 'session_id' => $v['slot']],
                [
                    'template_key'   => 'custom_software_saas',
                    'business_type'  => 'software_saas',
                    'is_active'      => false, // reference only — never wired to a real session
                    'agent_name'     => $v['agent_name'],
                    'tone'           => $v['tone'],
                    'languages'      => ['en', 'ar', 'hi', 'zh'],
                    'system_prompt'  => $v['system_prompt'],
                    'greeting_new'       => "👋 Hi! Welcome to Univexa Technologies. How can I help you today?",
                    'greeting_returning' => 'Welcome back! How can I help you today?',
                    'closing_message'    => 'Thanks for reaching out to Univexa Technologies.',
                    'fallback_transfer_message' => "I'll connect you with our team for a detailed answer on that.",
                    'qualification_questions' => $v['qualification_questions'],
                    'handoff'    => ['enabled' => true, 'notify_staff' => true],
                    'escalation' => $v['escalation'] ?? ['enabled' => true, 'keywords' => ['human', 'agent', 'representative']],
                    'payment'    => [],
                    'meta'       => array_merge(['seeded_by' => 'UnivexaCompanyContentSeeder'], $v['meta']),
                ]
            );
        }

        $this->command?->info('  └─ Playbooks: 1 active company-wide default + 2 inactive variants (sales-focused, support-focused) seeded.');
    }

    private function seedPipelines(): void
    {
        // is_active: false on every one of these — illustrative/reference pipelines, not
        // wired to a real session_id or a live trigger watcher, so seeding them can never
        // send a real WhatsApp message on its own.
        $pipelines = [
            [
                'name' => 'New Lead Welcome Sequence',
                'description' => 'Acknowledge a new lead and answer their first message with RAG if possible.',
                'trigger_type' => 'manual',
                'trigger_config' => [],
                'steps' => [
                    ['type' => 'rag_query', 'query' => '{{message}}'],
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => '{{rag_response}}'],
                ],
            ],
            [
                'name' => 'Discovery Call Booking Confirmation',
                'description' => 'Sent when a lead books a discovery call via the booking webhook.',
                'trigger_type' => 'webhook',
                'trigger_config' => ['source' => 'calendly', 'event' => 'invitee.created'],
                'steps' => [
                    ['type' => 'set_variable', 'key' => 'call_time', 'value' => '{{trigger_data.start_time}}'],
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => "Thanks for booking a discovery call for {{call_time}}! To make the most of it, jot down: what problem you're trying to solve, and your rough timeline/budget if you have one."],
                ],
            ],
            [
                'name' => 'Proposal Follow-Up (Day 3)',
                'description' => 'Nudges a lead who received a proposal but has not responded after 3 days.',
                'trigger_type' => 'cron',
                'trigger_config' => ['schedule' => '0 10 * * *', 'condition' => 'proposal_sent_3_days_ago_no_reply'],
                'steps' => [
                    ['type' => 'condition', 'variable' => '{{responded}}', 'operator' => 'not_empty'],
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => 'Hi! Just checking in on the proposal we sent over — happy to answer any questions or adjust the scope if needed.'],
                ],
            ],
            [
                'name' => 'Cold Lead Re-engagement (7-Day)',
                'description' => 'Re-engages a lead who went quiet for a week after their first inquiry.',
                'trigger_type' => 'cron',
                'trigger_config' => ['schedule' => '0 9 * * *', 'condition' => 'no_activity_7_days'],
                'steps' => [
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => "Hey — still interested in getting your project moving? Happy to pick the conversation back up whenever works for you."],
                ],
            ],
            [
                'name' => 'Payment Reminder',
                'description' => 'Reminds a client of an overdue invoice.',
                'trigger_type' => 'webhook',
                'trigger_config' => ['source' => 'invoicing', 'event' => 'invoice.overdue'],
                'steps' => [
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => 'Hi! A quick reminder that invoice {{invoice_number}} for {{invoice_amount}} was due on {{due_date}}. Let us know if you need anything to process it.'],
                ],
            ],
            [
                'name' => 'Onboarding Checklist Kickoff',
                'description' => 'Triggered when a client says they are ready to start after signing a proposal.',
                'trigger_type' => 'message',
                'trigger_config' => ['keywords' => ['start', 'kickoff', 'ready to begin']],
                'steps' => [
                    ['type' => 'rag_query', 'query' => 'what happens after I sign the proposal?'],
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => '{{rag_response}}'],
                ],
            ],
            [
                'name' => 'Support Ticket Auto-Acknowledgement',
                'description' => 'Answers an incoming bug/issue report from the knowledge base, or flags it for escalation if nothing relevant is found.',
                'trigger_type' => 'message',
                'trigger_config' => ['keywords' => ['bug', 'issue', 'error', 'not working', 'broken']],
                'steps' => [
                    ['type' => 'rag_query', 'query' => '{{message}}'],
                    ['type' => 'condition', 'variable' => '{{rag_status}}', 'operator' => 'equals', 'value' => 'fallback'],
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => '{{rag_response}}'],
                ],
            ],
            [
                'name' => 'Post-Project Testimonial Request',
                'description' => 'Sent manually after a project wraps up to request a testimonial.',
                'trigger_type' => 'manual',
                'trigger_config' => [],
                'steps' => [
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => "It was great working with you on this project! If you have 2 minutes, we'd really appreciate a short testimonial we can share — would you be open to that?"],
                ],
            ],
            [
                'name' => 'Renewal Reminder (30 Days Before)',
                'description' => 'Reminds a retainer/SaaS client their plan is renewing soon.',
                'trigger_type' => 'cron',
                'trigger_config' => ['schedule' => '0 9 * * *', 'condition' => 'renewal_in_30_days'],
                'steps' => [
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => "Just a heads-up — your plan renews on {{renewal_date}}. Let us know if you'd like to adjust anything before then."],
                ],
            ],
            [
                'name' => 'Referral Program Outreach',
                'description' => 'Asks a satisfied client for referrals, with an incentive.',
                'trigger_type' => 'manual',
                'trigger_config' => [],
                'steps' => [
                    ['type' => 'send_message', 'session_id' => '', 'phone' => '{{contact_phone}}', 'message' => "If you know anyone else who could use custom software or a SaaS build, we offer a referral credit on your next retainer month for any introduction that turns into a project — just have them mention your name!"],
                ],
            ],
        ];

        foreach ($pipelines as $p) {
            AiPipeline::updateOrCreate(
                ['company_id' => self::COMPANY_ID, 'name' => $p['name']],
                [
                    'description'    => $p['description'] . ' Inactive — wire a real session_id and trigger before enabling.',
                    'trigger_type'   => $p['trigger_type'],
                    'trigger_config' => $p['trigger_config'],
                    'is_active'      => false,
                    'steps'          => $p['steps'],
                ]
            );
        }

        $this->command?->info('  └─ Pipelines: ' . count($pipelines) . ' seeded (all inactive).');
    }
}
