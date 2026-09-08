<?php

namespace App\Modules\MetaAds\Services;

use App\Models\Company;
use App\Models\MetaAdAccount;
use App\Modules\WaChat\Services\Rag\LlmClient;
use Illuminate\Support\Facades\Log;

/**
 * "Describe your goal, get a campaign." Turns a short plain-language brief into a structured,
 * editable draft — objective, budget, an audience (in our normalized shape), and ad copy variants
 * — using the company's own configured LLM (via {@see LlmClient}). It only DRAFTS; creating the
 * campaign on Meta is the caller's separate, explicit step.
 */
class MetaCampaignPlanner
{
    /** Objectives we support end to end — the model is constrained to these. */
    private const OBJECTIVES = [
        'LEAD_GENERATION', 'LINK_CLICKS', 'CONVERSIONS', 'BRAND_AWARENESS',
        'REACH', 'VIDEO_VIEWS', 'MESSAGES',
    ];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly MetaAdsService $ads,
    ) {}

    public function isConfigured(Company $company): bool
    {
        return $this->llm->isConfigured($company);
    }

    /**
     * @param array{goal?:string,daily_budget?:float|int,audience_description?:string,locations?:string,
     *              business_name?:string,business_description?:string,website?:string,language?:string} $brief
     * @return array{campaign:array,ad_set:array,audience:array,creatives:array,notes:?string}
     */
    public function plan(Company $company, MetaAdAccount $account, array $brief): array
    {
        $system = $this->systemPrompt();
        $user = $this->userPrompt($brief, $account);

        $raw = $this->llm->chat($company, $system, [['role' => 'user', 'content' => $user]], 1500);
        if (!$raw) {
            throw new \RuntimeException('The AI provider is not configured or did not respond. Set your AI key in WA Agent → Settings.');
        }

        $plan = $this->decodeJson($raw);
        if (!$plan) {
            Log::warning('MetaCampaignPlanner: unparseable model output', ['raw' => mb_substr($raw, 0, 500)]);
            throw new \RuntimeException('The AI response could not be understood. Try rephrasing the brief.');
        }

        return $this->normalize($plan, $account);
    }

    // ── prompt ───────────────────────────────────────────────────────────

    private function systemPrompt(): string
    {
        $objectives = implode(', ', self::OBJECTIVES);
        return <<<PROMPT
        You are a senior Meta (Facebook/Instagram) ads strategist. Given a business brief, design ONE
        campaign with ONE ad set and 3 ad copy variants.

        Respond with STRICT JSON only — no markdown, no commentary. Shape:
        {
          "campaign": { "name": string, "objective": one of [$objectives], "special_ad_categories": string[] },
          "ad_set": { "name": string, "optimization_goal": string, "daily_budget": number, "billing_event": "IMPRESSIONS" },
          "audience": {
            "age_min": number, "age_max": number, "genders": "all"|"male"|"female",
            "geo_locations": { "countries": string[], "cities": string[] },
            "interests": string[],   // plain interest names, e.g. "Yoga", "Small business owners"
            "behaviors": string[]
          },
          "creatives": [ { "primary_text": string, "headline": string, "description": string, "call_to_action": string } ],
          "notes": string   // one or two sentences of rationale / what to tweak
        }

        Rules:
        - Pick the objective that best matches the stated goal. Use LEAD_GENERATION for "get leads/enquiries".
        - special_ad_categories is [] unless the business is clearly housing, employment, credit, politics or gambling.
        - daily_budget is in the account currency, a sensible number for the goal if the brief gives none.
        - Keep interest names real and specific; 3-6 of them. Cities only if the brief names a place.
        - call_to_action is a Meta enum like LEARN_MORE, SIGN_UP, GET_QUOTE, BOOK_TRAVEL, SHOP_NOW, CONTACT_US.
        - primary_text <= 150 words, headline <= 40 chars, description <= 30 chars.
        PROMPT;
    }

    private function userPrompt(array $brief, MetaAdAccount $account): string
    {
        $lines = [
            'Currency: ' . ($account->currency ?: 'INR'),
            'Business: ' . ($brief['business_name'] ?? '(unnamed)'),
            'What they do: ' . ($brief['business_description'] ?? '(not given)'),
            'Website: ' . ($brief['website'] ?? '(none)'),
            'Goal: ' . ($brief['goal'] ?? 'get more customers'),
            'Target audience (free text): ' . ($brief['audience_description'] ?? '(not given)'),
            'Locations: ' . ($brief['locations'] ?? '(not given)'),
            'Daily budget hint: ' . ($brief['daily_budget'] ?? '(decide a sensible amount)'),
            'Ad language: ' . ($brief['language'] ?? 'English'),
        ];
        return implode("\n", $lines);
    }

    // ── parsing + normalization ──────────────────────────────────────────

    private function decodeJson(string $raw): ?array
    {
        $raw = trim($raw);
        // Strip ```json fences if the model added them anyway.
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
        // Grab the outermost {...} in case of stray prose.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalize(array $plan, MetaAdAccount $account): array
    {
        $c = $plan['campaign'] ?? [];
        $a = $plan['ad_set'] ?? [];
        $aud = $plan['audience'] ?? [];

        $objective = strtoupper($c['objective'] ?? 'LEAD_GENERATION');
        if (!in_array($objective, self::OBJECTIVES, true)) {
            $objective = 'LEAD_GENERATION';
        }

        $specialCats = collect($c['special_ad_categories'] ?? [])
            ->map(fn ($x) => strtoupper((string) $x))
            ->filter(fn ($x) => in_array($x, MetaAdsService::SPECIAL_AD_CATEGORIES, true))
            ->values()->all();

        // Resolve interest / behaviour NAMES to real Meta targeting ids (best-effort, top hit).
        $interests = $this->resolveTargeting($account, $aud['interests'] ?? [], 'adinterest');
        $behaviors = $this->resolveTargeting($account, $aud['behaviors'] ?? [], 'adTargetingCategory');

        $audience = [
            'name'      => 'AI: ' . ($c['name'] ?? 'campaign') . ' audience',
            'age_min'   => (int) ($aud['age_min'] ?? 18),
            'age_max'   => (int) ($aud['age_max'] ?? 65),
            'genders'   => in_array(($aud['genders'] ?? 'all'), ['male', 'female'], true) ? $aud['genders'] : 'all',
            'geo_locations' => [
                'countries' => $aud['geo_locations']['countries'] ?? [$account->currency === 'INR' ? 'IN' : 'US'],
            ],
            'interests' => $interests,
            'behaviors' => $behaviors,
            'placements' => ['automatic' => true],
            '_interest_terms' => $aud['interests'] ?? [],   // kept so the UI can show unresolved terms
        ];
        if (!empty($aud['geo_locations']['cities'])) {
            $audience['geo_locations']['_city_terms'] = $aud['geo_locations']['cities'];
        }

        $creatives = collect($plan['creatives'] ?? [])->take(3)->map(fn ($cr) => [
            'primary_text'   => (string) ($cr['primary_text'] ?? ''),
            'headline'       => mb_substr((string) ($cr['headline'] ?? ''), 0, 40),
            'description'    => mb_substr((string) ($cr['description'] ?? ''), 0, 30),
            'call_to_action' => strtoupper($cr['call_to_action'] ?? 'LEARN_MORE'),
        ])->values()->all();

        return [
            'campaign' => [
                'name'                  => (string) ($c['name'] ?? 'AI campaign'),
                'objective'             => $objective,
                'special_ad_categories' => $specialCats,
            ],
            'ad_set' => [
                'name'              => (string) ($a['name'] ?? ($c['name'] ?? 'Ad set')),
                'optimization_goal' => strtoupper($a['optimization_goal'] ?? ($objective === 'LEAD_GENERATION' ? 'LEAD_GENERATION' : 'LINK_CLICKS')),
                'billing_event'     => 'IMPRESSIONS',
                'daily_budget'      => max((float) ($a['daily_budget'] ?? 500), 40),
            ],
            'audience'  => $audience,
            'creatives' => $creatives,
            'notes'     => $plan['notes'] ?? null,
        ];
    }

    /** @return array<array{id:string,name:string}> */
    private function resolveTargeting(MetaAdAccount $account, array $names, string $type): array
    {
        $out = [];
        foreach (array_slice($names, 0, 6) as $name) {
            if (!is_string($name) || trim($name) === '') continue;
            try {
                $hits = $this->ads->searchTargeting($account, $name, $type);
                $top = $hits[0] ?? null;
                if ($top && !empty($top['id'])) {
                    $out[] = ['id' => (string) $top['id'], 'name' => $top['name'] ?? $name];
                }
            } catch (\Throwable $e) {
                Log::debug('MetaCampaignPlanner: targeting search failed', ['name' => $name, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /**
     * Execute an (edited) draft: create the campaign + ad set on Meta. Creative/ad creation stays
     * separate because it needs media — but if the caller supplies an image/video/lead form we
     * build the first creative + ad too.
     *
     * @return array{campaign_id:int, ad_set_id:int, ad_id:?int}
     */
    public function build(MetaAdAccount $account, array $draft): array
    {
        $campaign = $this->ads->createCampaign($account, [
            'name'                  => $draft['campaign']['name'],
            'objective'             => $draft['campaign']['objective'],
            'special_ad_categories' => $draft['campaign']['special_ad_categories'] ?? [],
        ]);

        $adSet = $this->ads->createAdSet($campaign, [
            'name'              => $draft['ad_set']['name'],
            'optimization_goal' => $draft['ad_set']['optimization_goal'],
            'billing_event'     => $draft['ad_set']['billing_event'] ?? 'IMPRESSIONS',
            'daily_budget'      => $draft['ad_set']['daily_budget'],
            'audience'          => $draft['audience'],
        ]);

        $adId = null;
        $firstCreative = $draft['creatives'][0] ?? null;
        $media = $draft['media'] ?? [];
        if ($firstCreative && (!empty($media['image_id']) || !empty($media['video_id']) || !empty($media['lead_form_id']))) {
            $creative = $this->ads->createCreative($account, array_merge($firstCreative, [
                'format'       => !empty($media['video_id']) ? 'video' : 'image',
                'image_id'     => $media['image_id'] ?? null,
                'video_id'     => $media['video_id'] ?? null,
                'lead_form_id' => $media['lead_form_id'] ?? null,
                'name'         => $draft['campaign']['name'] . ' creative',
            ]));
            $ad = $this->ads->createAd($adSet, $creative, ['name' => $draft['campaign']['name'] . ' ad', 'publish' => false]);
            $adId = $ad->id;
        }

        return ['campaign_id' => $campaign->id, 'ad_set_id' => $adSet->id, 'ad_id' => $adId];
    }
}
