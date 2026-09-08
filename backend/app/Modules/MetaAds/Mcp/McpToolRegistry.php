<?php

namespace App\Modules\MetaAds\Mcp;

use App\Models\{MetaAdAccount, MetaAudienceSet, MetaCampaign, MetaLead, MetaLeadForm};
use App\Modules\MetaAds\Services\{MetaAdsService, MetaCampaignPlanner};

/**
 * The Meta Ads tools exposed over MCP. Every handler is company-scoped to the authenticated user
 * and returns a plain array that the controller serialises as the tool result. Writes are limited
 * to create + status changes — nothing here deletes.
 */
class McpToolRegistry
{
    public function __construct(
        private readonly MetaAdsService $ads,
        private readonly MetaCampaignPlanner $planner,
    ) {}

    private function companyId(): int
    {
        return auth()->user()->company_id;
    }

    /** @return array<int, array{name:string, description:string, inputSchema:array}> */
    public function list(): array
    {
        return array_map(fn ($t) => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'inputSchema' => $t['inputSchema'],
        ], $this->tools());
    }

    /** @return array{content: array<int, array{type:string, text:string}>, isError?: bool} */
    public function call(string $name, array $args): array
    {
        $tool = collect($this->tools())->firstWhere('name', $name);
        if (!$tool) {
            return $this->err("Unknown tool: {$name}");
        }
        try {
            $result = ($tool['handler'])($args);
            return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]]];
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    private function err(string $msg): array
    {
        return ['content' => [['type' => 'text', 'text' => $msg]], 'isError' => true];
    }

    // ── tool table ───────────────────────────────────────────────────────

    private function tools(): array
    {
        $str = fn (string $d) => ['type' => 'string', 'description' => $d];
        $num = fn (string $d) => ['type' => 'number', 'description' => $d];

        return [
            [
                'name' => 'list_ad_accounts',
                'description' => 'List the Meta ad accounts connected to this company.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'handler' => fn () => MetaAdAccount::where('company_id', $this->companyId())
                    ->get(['id', 'ad_account_id', 'ad_account_name', 'currency', 'is_default', 'account_status'])->toArray(),
            ],
            [
                'name' => 'list_campaigns',
                'description' => 'List this company\'s Meta campaigns, newest first. Optional status filter (ACTIVE, PAUSED, ARCHIVED).',
                'inputSchema' => ['type' => 'object', 'properties' => ['status' => $str('ACTIVE | PAUSED | ARCHIVED')]],
                'handler' => fn ($a) => MetaCampaign::where('company_id', $this->companyId())
                    ->when($a['status'] ?? null, fn ($q, $s) => $q->where('status', strtoupper($s)))
                    ->latest()->limit(50)
                    ->get(['id', 'name', 'objective', 'status', 'meta_campaign_id', 'created_at'])->toArray(),
            ],
            [
                'name' => 'create_campaign',
                'description' => 'Create a Meta campaign (starts PAUSED). Returns the new campaign.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['account_id', 'name', 'objective'],
                    'properties' => [
                        'account_id' => $num('id from list_ad_accounts'),
                        'name'       => $str('campaign name'),
                        'objective'  => $str('LEAD_GENERATION | LINK_CLICKS | CONVERSIONS | REACH | VIDEO_VIEWS | MESSAGES | BRAND_AWARENESS'),
                        'special_ad_categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'HOUSING/EMPLOYMENT/CREDIT/ISSUES_ELECTIONS_POLITICS/ONLINE_GAMBLING_AND_GAMING, or omit'],
                    ],
                ],
                'handler' => function ($a) {
                    $account = $this->account((int) $a['account_id']);
                    $c = $this->ads->createCampaign($account, [
                        'name' => $a['name'],
                        'objective' => strtoupper($a['objective']),
                        'special_ad_categories' => $a['special_ad_categories'] ?? [],
                    ]);
                    return $c->only(['id', 'name', 'objective', 'status', 'meta_campaign_id']);
                },
            ],
            [
                'name' => 'set_campaign_status',
                'description' => 'Pause, resume (ACTIVE) or archive a campaign.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['campaign_id', 'status'],
                    'properties' => ['campaign_id' => $num('local campaign id'), 'status' => $str('ACTIVE | PAUSED | ARCHIVED')],
                ],
                'handler' => function ($a) {
                    $c = MetaCampaign::where('id', $a['campaign_id'])->where('company_id', $this->companyId())->firstOrFail();
                    $this->ads->updateCampaignStatus($c, strtoupper($a['status']));
                    return ['id' => $c->id, 'status' => strtoupper($a['status'])];
                },
            ],
            [
                'name' => 'list_audience_sets',
                'description' => 'List the company\'s saved, reusable audience sets.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'handler' => fn () => MetaAudienceSet::where('company_id', $this->companyId())
                    ->orderByDesc('use_count')
                    ->get(['id', 'name', 'age_min', 'age_max', 'genders', 'use_count', 'reach_min', 'reach_max'])->toArray(),
            ],
            [
                'name' => 'create_ad_set',
                'description' => 'Create an ad set under a campaign. Provide either audience_set_id (reuse a saved audience) or nothing to fail — this tool does not build targeting from scratch.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['campaign_id', 'name', 'daily_budget', 'audience_set_id'],
                    'properties' => [
                        'campaign_id'     => $num('local campaign id'),
                        'name'            => $str('ad set name'),
                        'daily_budget'    => $num('daily budget in account currency'),
                        'audience_set_id' => $num('id from list_audience_sets'),
                        'optimization_goal' => $str('e.g. LEAD_GENERATION, LINK_CLICKS, REACH'),
                    ],
                ],
                'handler' => function ($a) {
                    $c = MetaCampaign::where('id', $a['campaign_id'])->where('company_id', $this->companyId())->firstOrFail();
                    $set = $this->ads->createAdSet($c, [
                        'name' => $a['name'],
                        'optimization_goal' => strtoupper($a['optimization_goal'] ?? 'LEAD_GENERATION'),
                        'billing_event' => 'IMPRESSIONS',
                        'daily_budget' => (float) $a['daily_budget'],
                        'audience_set_id' => (int) $a['audience_set_id'],
                    ]);
                    return $set->only(['id', 'name', 'status', 'meta_adset_id', 'daily_budget']);
                },
            ],
            [
                'name' => 'get_campaign_insights',
                'description' => 'Stored daily insights for a campaign over a date range (defaults to last 30 days).',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['campaign_id'],
                    'properties' => ['campaign_id' => $num('local campaign id'), 'from' => $str('YYYY-MM-DD'), 'to' => $str('YYYY-MM-DD')],
                ],
                'handler' => function ($a) {
                    $c = MetaCampaign::where('id', $a['campaign_id'])->where('company_id', $this->companyId())->firstOrFail();
                    return $c->insights()
                        ->whereBetween('date', [$a['from'] ?? now()->subDays(30)->toDateString(), $a['to'] ?? now()->toDateString()])
                        ->orderBy('date')
                        ->get(['date', 'impressions', 'reach', 'clicks', 'link_clicks', 'spend', 'leads', 'cost_per_lead', 'ctr', 'cpm'])
                        ->toArray();
                },
            ],
            [
                'name' => 'sync_campaign_insights',
                'description' => 'Pull fresh insights for a campaign from Meta (last 7 days by default).',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['campaign_id'],
                    'properties' => ['campaign_id' => $num('local campaign id'), 'from' => $str('YYYY-MM-DD'), 'to' => $str('YYYY-MM-DD')],
                ],
                'handler' => function ($a) {
                    $c = MetaCampaign::where('id', $a['campaign_id'])->where('company_id', $this->companyId())->firstOrFail();
                    $this->ads->syncInsights($c, $a['from'] ?? now()->subDays(7)->toDateString(), $a['to'] ?? now()->toDateString());
                    return ['synced' => true, 'campaign_id' => $c->id];
                },
            ],
            [
                'name' => 'plan_campaign_with_ai',
                'description' => 'Draft a full campaign (objective, budget, audience, ad copy) from a plain-language brief. Returns an editable draft — it does NOT create anything.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['account_id', 'goal'],
                    'properties' => [
                        'account_id' => $num('id from list_ad_accounts'),
                        'goal' => $str('what the campaign should achieve'),
                        'business_name' => $str(''),
                        'business_description' => $str(''),
                        'audience_description' => $str('who to target, free text'),
                        'locations' => $str('cities / regions'),
                        'daily_budget' => $num('optional'),
                    ],
                ],
                'handler' => function ($a) {
                    $account = $this->account((int) $a['account_id']);
                    return $this->planner->plan(auth()->user()->company, $account, $a);
                },
            ],
            [
                'name' => 'list_lead_forms',
                'description' => 'List Meta Instant (lead) Forms known to this company.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'handler' => fn () => MetaLeadForm::where('company_id', $this->companyId())
                    ->get(['id', 'meta_form_id', 'name', 'status', 'leads_count'])->toArray(),
            ],
            [
                'name' => 'list_recent_leads',
                'description' => 'The most recent lead-ad leads and their CRM processing status.',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => $num('default 20, max 100')]],
                'handler' => fn ($a) => MetaLead::where('company_id', $this->companyId())
                    ->with(['campaign:id,name'])
                    ->latest()->limit(min((int) ($a['limit'] ?? 20), 100))
                    ->get(['id', 'full_name', 'phone', 'email', 'process_status', 'meta_campaign_id', 'meta_created_time'])
                    ->toArray(),
            ],
        ];
    }

    private function account(int $id): MetaAdAccount
    {
        return MetaAdAccount::where('id', $id)->where('company_id', $this->companyId())->firstOrFail();
    }
}
