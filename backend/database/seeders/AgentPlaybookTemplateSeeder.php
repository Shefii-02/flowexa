<?php

namespace Database\Seeders;

use App\Modules\WaChat\Models\AgentPlaybookTemplate;
use Illuminate\Database\Seeder;

class AgentPlaybookTemplateSeeder extends Seeder
{
    /**
     * Shared language / style rules appended to every template's system prompt.
     * The model itself does the language detection — cheaper and far more robust
     * than a PHP script for romanised / code-mixed input (Manglish, Hinglish…).
     */
    private const LANGUAGE_RULES = <<<'TXT'
LANGUAGE & STYLE:
- Detect the customer's language AND writing style from their message. It may be
  English, Malayalam, Hindi, Kannada, Tamil, Arabic, etc.; it may be written in
  the native script OR romanised/transliterated (e.g. "Manglish", "Hinglish"); it
  may mix two languages in one sentence (code-switching).
- Always reply in the SAME language and the SAME style/script the customer used.
  If they wrote Manglish, reply in Manglish. If they switched languages, mirror
  that. If unsure, use simple English.
- Keep replies short and WhatsApp-friendly (1-4 short sentences). Use line breaks,
  not long paragraphs.
- Share a link or attach a file only when it genuinely helps the customer.
TXT;

    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            $tpl['default_config']['system_prompt'] = trim(
                $tpl['default_config']['system_prompt'] . "\n\n" . self::LANGUAGE_RULES
            );

            AgentPlaybookTemplate::updateOrCreate(['key' => $tpl['key']], $tpl);
        }
    }

    private function templates(): array
    {
        return [
            // ── LMS / Education ────────────────────────────────────────────────
            [
                'key'         => 'lms',
                'name'        => 'Education / LMS',
                'description' => 'Course enquiries → counselling → admission. For coaching centres, ed-tech, training institutes.',
                'icon'        => '🎓',
                'is_active'   => true,
                'sort_order'  => 1,
                'default_config' => [
                    'agent_name' => 'Admissions Assistant',
                    'tone'       => 'warm, encouraging, helpful',
                    'languages'  => ['auto'],
                    'system_prompt' =>
                        "You are the admissions assistant for an education provider. Your job is to understand "
                        . "what the student wants to learn, answer questions about courses, fees, schedule and "
                        . "eligibility using ONLY the knowledge base, and guide them towards enrolling. Never invent "
                        . "course details or prices. If a question is outside the knowledge base, say a counsellor "
                        . "will help and continue collecting their details.",
                    'greeting_new' =>
                        "Hi! 👋 Thanks for reaching out. I can help you find the right course and guide you through "
                        . "admission. May I ask a few quick questions?",
                    'greeting_returning' =>
                        "Welcome back! 👋 Shall we continue with your course enquiry?",
                    'closing_message' =>
                        "Perfect — I have everything I need. Our admissions counsellor will contact you shortly to "
                        . "complete the process. 🎓",
                    'fallback_transfer_message' =>
                        "That's a great question — let me connect you with a counsellor who can give you the exact details.",
                    'qualification_questions' => [
                        ['key' => 'customer_status', 'question' => 'Are you a new student or already enrolled with us?', 'type' => 'choice', 'options' => ['New', 'Existing'], 'required' => true],
                        ['key' => 'course_interest', 'question' => 'Which course or subject are you interested in?', 'type' => 'text', 'required' => true],
                        ['key' => 'goal',           'question' => 'What is your main goal? (career change, upskilling, exam prep, hobby)', 'type' => 'text', 'required' => false],
                        ['key' => 'mode',           'question' => 'Do you prefer online or classroom learning?', 'type' => 'choice', 'options' => ['Online', 'Classroom', 'Either'], 'required' => true],
                        ['key' => 'start_time',     'question' => 'When would you like to start?', 'type' => 'text', 'required' => true],
                        ['key' => 'city',           'question' => 'Which city are you in?', 'type' => 'text', 'required' => false],
                        ['key' => 'budget',         'question' => 'Do you have a budget range in mind for the course fee?', 'type' => 'text', 'required' => false],
                    ],
                    'handoff' => [
                        'on_complete'       => 'update_lead',
                        'lead_stage'        => 'qualified',
                        'lead_category'     => 'course_enquiry',
                        'assign_strategy'   => 'round_robin',
                        'notify_roles'      => ['counsellor', 'admin'],
                        'task_template'     => 'Call {contact_name} about {course_interest} admission',
                        'pipeline_key'      => null,
                        'transfer_to_human' => true,
                    ],
                    'escalation' => [
                        'buying_signal_score' => 80,
                        'keywords'            => ['talk to counsellor', 'call me', 'speak to someone', 'human', 'agent'],
                        'max_unanswered'      => 3,
                        'on_escalate_message' => "Sure — I'm connecting you with a counsellor now. They'll call you shortly.",
                    ],
                    'payment' => [
                        'enabled'            => false,
                        'mode'               => 'manual',
                        'provider'           => null,
                        'currency'           => 'INR',
                        'instructions'       => 'Counsellor shares fee details and payment link after the call.',
                        'on_confirm_message' => "Here's your admission payment link: {payment_link}\nOnce paid, your seat is confirmed. 🎓",
                    ],
                ],
            ],

            // ── Real Estate ───────────────────────────────────────────────────
            [
                'key'         => 'real_estate',
                'name'        => 'Real Estate',
                'description' => 'Buy / sell / rent enquiries → requirement capture → site visit. For brokers, builders, property portals.',
                'icon'        => '🏠',
                'is_active'   => true,
                'sort_order'  => 2,
                'default_config' => [
                    'agent_name' => 'Property Assistant',
                    'tone'       => 'professional, concise, consultative',
                    'languages'  => ['auto'],
                    'system_prompt' =>
                        "You are a real-estate assistant. Understand whether the customer wants to buy, sell or rent, "
                        . "capture their requirement precisely (property type, location, budget, timeline), answer "
                        . "questions about available listings using ONLY the knowledge base, and move them towards a "
                        . "site visit or a call with an agent. Never quote prices or availability that are not in the "
                        . "knowledge base.",
                    'greeting_new' =>
                        "Hello! 👋 I can help you find the right property. Could you answer a few quick questions so I "
                        . "can shortlist the best options?",
                    'greeting_returning' =>
                        "Welcome back! 👋 Shall we continue with your property search?",
                    'closing_message' =>
                        "Great — I've noted your requirements. Our property consultant will contact you to arrange "
                        . "viewings that match. 🏠",
                    'fallback_transfer_message' =>
                        "Let me get our property consultant to share the exact details with you.",
                    'qualification_questions' => [
                        ['key' => 'intent',        'question' => 'Are you looking to buy, sell or rent?', 'type' => 'choice', 'options' => ['Buy', 'Sell', 'Rent'], 'required' => true],
                        ['key' => 'property_type', 'question' => 'What type of property? (apartment, villa, plot, commercial)', 'type' => 'text', 'required' => true],
                        ['key' => 'location',      'question' => 'Which area / locality are you interested in?', 'type' => 'text', 'required' => true],
                        ['key' => 'budget',        'question' => 'What is your budget range?', 'type' => 'text', 'required' => true],
                        ['key' => 'config',        'question' => 'Any size / configuration preference? (e.g. 2BHK, 3BHK, sq.ft.)', 'type' => 'text', 'required' => false],
                        ['key' => 'timeline',      'question' => 'What is your timeline to close?', 'type' => 'text', 'required' => true],
                        ['key' => 'financing',     'question' => 'Will you need a home loan, or is it a cash purchase?', 'type' => 'choice', 'options' => ['Loan', 'Cash', 'Not sure'], 'required' => false],
                    ],
                    'handoff' => [
                        'on_complete'       => 'update_lead',
                        'lead_stage'        => 'qualified',
                        'lead_category'     => 'property_enquiry',
                        'assign_strategy'   => 'round_robin',
                        'notify_roles'      => ['team_lead', 'admin'],
                        'task_template'     => 'Arrange site visit for {contact_name} — {property_type} in {location}',
                        'pipeline_key'      => null,
                        'transfer_to_human' => true,
                    ],
                    'escalation' => [
                        'buying_signal_score' => 75,
                        'keywords'            => ['call me', 'speak to agent', 'site visit', 'visit today', 'human'],
                        'max_unanswered'      => 3,
                        'on_escalate_message' => "I'm connecting you with our property consultant — they'll call you shortly.",
                    ],
                    'payment' => [
                        'enabled'            => false,
                        'mode'               => 'manual',
                        'provider'           => null,
                        'currency'           => 'INR',
                        'instructions'       => 'Token / booking amount handled by the consultant after the site visit.',
                        'on_confirm_message' => "Here's the booking payment link: {payment_link}\nYour unit is held once we receive the token amount.",
                    ],
                ],
            ],

            // ── Generic Services / Products ───────────────────────────────────
            [
                'key'         => 'services',
                'name'        => 'Services & Sales',
                'description' => 'General enquiry → needs capture → quote / order. For clinics, salons, agencies, retailers, service businesses.',
                'icon'        => '🛍️',
                'is_active'   => true,
                'sort_order'  => 3,
                'default_config' => [
                    'agent_name' => 'Sales Assistant',
                    'tone'       => 'friendly, efficient, helpful',
                    'languages'  => ['auto'],
                    'system_prompt' =>
                        "You are a sales assistant for a service/retail business. Understand what the customer needs, "
                        . "answer questions about services, products, pricing and availability using ONLY the knowledge "
                        . "base, and move them towards booking or placing an order. Never invent prices or availability. "
                        . "When the customer is ready to buy, confirm the details before handing off.",
                    'greeting_new' =>
                        "Hi there! 👋 How can I help you today? Tell me what you're looking for and I'll take it from there.",
                    'greeting_returning' =>
                        "Welcome back! 👋 How can I help you today?",
                    'closing_message' =>
                        "All set — I've passed your details to our team and they'll follow up shortly to confirm. 🙌",
                    'fallback_transfer_message' =>
                        "Let me check with our team and get back to you with the exact details.",
                    'qualification_questions' => [
                        ['key' => 'customer_status', 'question' => 'Have you used our services before, or is this your first time?', 'type' => 'choice', 'options' => ['New', 'Returning'], 'required' => false],
                        ['key' => 'need',            'question' => 'What service or product are you interested in?', 'type' => 'text', 'required' => true],
                        ['key' => 'details',         'question' => 'Any specific requirements or preferences?', 'type' => 'text', 'required' => false],
                        ['key' => 'quantity',        'question' => 'How many / how much do you need?', 'type' => 'text', 'required' => false],
                        ['key' => 'timeline',        'question' => 'When do you need this?', 'type' => 'text', 'required' => true],
                        ['key' => 'location',        'question' => 'Which location / area is this for?', 'type' => 'text', 'required' => false],
                        ['key' => 'budget',          'question' => 'Do you have a budget in mind?', 'type' => 'text', 'required' => false],
                    ],
                    'handoff' => [
                        'on_complete'       => 'update_lead',
                        'lead_stage'        => 'qualified',
                        'lead_category'     => 'sales_enquiry',
                        'assign_strategy'   => 'round_robin',
                        'notify_roles'      => ['admin'],
                        'task_template'     => 'Follow up with {contact_name} about {need}',
                        'pipeline_key'      => null,
                        'transfer_to_human' => true,
                    ],
                    'escalation' => [
                        'buying_signal_score' => 80,
                        'keywords'            => ['call me', 'speak to someone', 'human', 'agent', 'order now', 'buy now'],
                        'max_unanswered'      => 3,
                        'on_escalate_message' => "One moment — I'm connecting you with our team now.",
                    ],
                    'payment' => [
                        'enabled'            => false,
                        'mode'               => 'manual',
                        'provider'           => null,
                        'currency'           => 'INR',
                        'instructions'       => 'Team shares the final quote and payment link to confirm the order.',
                        'on_confirm_message' => "Here's your payment link to confirm the order: {payment_link}\nSend a screenshot once done and we'll process it right away.",
                    ],
                ],
            ],
        ];
    }
}
