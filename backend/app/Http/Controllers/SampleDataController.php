<?php

namespace App\Http\Controllers;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Models\SurveyForm;
use App\Models\WaTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * "Add sample data" buttons on WA Templates, Survey Forms and Flow Builders.
 *
 * A brand-new company starts with all three lists empty and no hint of what a good
 * entry looks like. Each endpoint installs two ready-made samples for the caller's
 * company. They are idempotent: samples are matched by their fixed name, so tapping
 * the button again never duplicates anything — when every sample already exists the
 * response is `{ created: 0, already_exists: true }` (HTTP 200) and the UI shows an
 * alert; otherwise only the missing ones are created.
 *
 * Nothing here talks to Meta: templates stay local drafts, survey forms are not
 * published as WhatsApp Flows, and flow builders are created inactive (keyword
 * triggered) so a sample can never hijack real customer conversations.
 */
class SampleDataController extends Controller
{
    // POST /templates/seed-samples
    public function templates(): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $samples = [
            [
                'name'          => 'sample_welcome_offer',
                'category'      => 'MARKETING',
                'body'          => "Hi {{1}}, welcome to {{2}}! 🎉 Enjoy 10% off your first order.",
                'body_examples' => ['Alex', 'Acme Store'],
                'header'        => 'Welcome aboard',
                'footer'        => 'Reply STOP to opt out',
                'buttons'       => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Shop now'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Talk to us'],
                ],
            ],
            [
                'name'          => 'sample_order_update',
                'category'      => 'UTILITY',
                'body'          => "Hi {{1}}, your order {{2}} has been shipped and will arrive soon.",
                'body_examples' => ['Alex', '#10234'],
                'header'        => null,
                'footer'        => null,
                'buttons'       => null,
            ],
        ];

        return $this->seed($samples, 'templates', fn (array $s) => WaTemplate::where('company_id', $cid)->where('name', $s['name'])->exists(),
            function (array $s) use ($cid) {
                WaTemplate::create([
                    'company_id'    => $cid,
                    'name'          => $s['name'],
                    'category'      => $s['category'],
                    'language'      => 'en',
                    'body'          => $s['body'],
                    'body_examples' => $s['body_examples'],
                    'header_format' => 'TEXT',
                    'header'        => $s['header'],
                    'footer'        => $s['footer'],
                    'buttons'       => $s['buttons'],
                    'status'        => 'draft',
                ]);
            });
    }

    // POST /survey-forms/seed-samples
    public function surveyForms(): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $samples = [
            [
                'name'        => 'Sample – Customer Feedback',
                'description' => 'Quick satisfaction survey after a purchase or support chat.',
                'fields'      => [
                    ['key' => 'rating', 'question_text' => 'How would you rate your experience?', 'type' => 'choice',
                     'options' => ['Excellent', 'Good', 'Average', 'Poor'], 'required' => true],
                    ['key' => 'recommend', 'question_text' => 'How likely are you to recommend us (0-10)?', 'type' => 'number', 'required' => true],
                    ['key' => 'comments', 'question_text' => 'Anything we could do better?', 'type' => 'text', 'required' => false],
                ],
            ],
            [
                'name'        => 'Sample – Lead Capture',
                'description' => 'Collect basic contact details and interest from a new enquiry.',
                'fields'      => [
                    ['key' => 'full_name', 'question_text' => 'What is your full name?', 'type' => 'text', 'required' => true],
                    ['key' => 'interest', 'question_text' => 'What are you interested in?', 'type' => 'choice',
                     'options' => ['Product demo', 'Pricing', 'Support', 'Other'], 'required' => true],
                    ['key' => 'budget', 'question_text' => 'What is your approximate budget?', 'type' => 'number', 'required' => false],
                ],
            ],
        ];

        return $this->seed($samples, 'survey forms', fn (array $s) => SurveyForm::where('company_id', $cid)->where('name', $s['name'])->exists(),
            function (array $s) use ($cid) {
                SurveyForm::create([
                    'company_id'  => $cid,
                    'name'        => $s['name'],
                    'description' => $s['description'],
                    'fields'      => $s['fields'],
                    'is_active'   => true,
                ]);
            });
    }

    // POST /flow-builders/seed-samples
    public function flowBuilders(): JsonResponse
    {
        $cid = auth()->user()->company_id;

        // One root node (button or list) with text replies underneath. reply_ids are
        // prefixed per sample because flow_nodes.reply_id is unique per company.
        $samples = [
            [
                'name'        => 'Sample – Support Menu',
                'description' => 'Welcome buttons that route customers to sales, support or a human.',
                'keywords'    => ['sample support'],
                'prefix'      => 'sample_support',
                'root_type'   => 'button',
                'root_msg'    => 'Hi 👋 Welcome! How can we help you today?',
                'children'    => [
                    ['Sales',        "Great! Our sales team will contact you shortly. Please share what you're looking for."],
                    ['Support',      'Sorry for the trouble. Please describe the issue and we will get back to you.'],
                    ['Talk to agent', 'Connecting you with a team member. Please hold on.'],
                ],
            ],
            [
                'name'        => 'Sample – Product Menu',
                'description' => 'A list menu showing product categories with a reply for each.',
                'keywords'    => ['sample menu'],
                'prefix'      => 'sample_menu',
                'root_type'   => 'list',
                'root_msg'    => 'Browse our catalogue — pick a category below.',
                'children'    => [
                    ['New arrivals', 'Here are our newest products. Reply with a product name for details.'],
                    ['Best sellers', 'These are our most loved products. Reply with a product name for details.'],
                    ['Offers',       'Current offers: 10% off on your first order. Ask us how to redeem.'],
                    ['Contact us',   'You can reach us any time on this chat.'],
                ],
            ],
        ];

        return $this->seed($samples, 'flow builders', fn (array $s) => FlowBuilder::where('company_id', $cid)->where('name', $s['name'])->exists(),
            function (array $s) use ($cid) {
                DB::transaction(function () use ($s, $cid) {
                    $builder = FlowBuilder::create([
                        'company_id'       => $cid,
                        'created_by'       => auth()->id(),
                        'name'             => $s['name'],
                        'description'      => $s['description'],
                        'is_active'        => false,
                        'trigger_type'     => 'keyword',
                        'trigger_keywords' => $s['keywords'],
                    ]);

                    $root = FlowNode::create([
                        'company_id'      => $cid,
                        'flow_builder_id' => $builder->id,
                        'parent_id'       => null,
                        'title'           => 'Welcome',
                        'message'         => $s['root_msg'],
                        'type'            => $s['root_type'],
                        'reply_id'        => $s['prefix'] . '_welcome',
                        'sort_order'      => 0,
                        'is_active'       => true,
                    ]);

                    foreach ($s['children'] as $i => [$title, $message]) {
                        FlowNode::create([
                            'company_id'      => $cid,
                            'flow_builder_id' => $builder->id,
                            'parent_id'       => $root->id,
                            'title'           => $title,
                            'message'         => $message,
                            'type'            => 'text',
                            'reply_id'        => $s['prefix'] . '_' . ($i + 1),
                            'sort_order'      => $i,
                            'is_active'       => true,
                        ]);
                    }
                });
            });
    }

    /**
     * Create whichever samples the company doesn't have yet.
     *
     * @param  array<int, array>  $samples
     */
    private function seed(array $samples, string $label, callable $exists, callable $create): JsonResponse
    {
        $created = 0;
        foreach ($samples as $sample) {
            if ($exists($sample)) {
                continue;
            }
            $create($sample);
            $created++;
        }

        if ($created === 0) {
            return response()->json([
                'created'        => 0,
                'already_exists' => true,
                'message'        => "Sample {$label} already exist for your company — nothing was added.",
            ]);
        }

        return response()->json([
            'created'        => $created,
            'already_exists' => false,
            'message'        => "Added {$created} sample {$label}.",
        ], 201);
    }
}
