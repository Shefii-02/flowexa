<?php
// app/Modules/MetaAds/Services/MetaAdsService.php

namespace App\Modules\MetaAds\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MetaAdAccount;
use App\Models\MetaCampaign;
use App\Models\MetaAdSet;
use App\Models\MetaAdCreative;
use App\Models\MetaAd;
use App\Models\MetaInsight;
use App\Models\MetaMediaLibrary;
use App\Models\MetaAudienceSet;

class MetaAdsService
{
    private ?MetaGraphClient $graph = null;

    /** Base for the couple of raw multipart uploads that don't fit the JSON graph client. */
    private function graphUploadUrl(): string
    {
        return 'https://graph.facebook.com/' . config('services.meta_ads.graph_version', 'v21.0');
    }

    /** The five special ad categories Meta recognises (anything else is rejected at campaign create). */
    public const SPECIAL_AD_CATEGORIES = [
        'HOUSING', 'EMPLOYMENT', 'CREDIT', 'ISSUES_ELECTIONS_POLITICS', 'ONLINE_GAMBLING_AND_GAMING',
    ];

    // ── Connect ad account ────────────────────────────────────────────────
    public function connectAdAccount(int $companyId, array $data): MetaAdAccount
    {
        Log::alert("MetaAdsService: Connecting ad account for company {$companyId} with data: " . json_encode($data));
        // Validate token by fetching account info
        $info = $this->get("/{$data['ad_account_id']}", $data['access_token'], [
            'fields' => 'name,currency,timezone_name,account_status,business',
        ]);

        Log::info("MetaAdsService: Fetched account info for {$data['ad_account_id']}: " . json_encode($info));

        if (!isset($info['name'])) {
            throw new \Exception('Invalid ad account or access token. Please check your credentials.');
        }

        // Make first connected account the default
        $isDefault = !MetaAdAccount::where('company_id', $companyId)->where('is_active', true)->exists();

        return MetaAdAccount::updateOrCreate(
            ['company_id' => $companyId, 'ad_account_id' => $data['ad_account_id']],
            [
                'ad_account_name' => $info['name'],
                'page_id'         => $data['page_id'] ?? null,
                'page_name'       => $data['page_name'] ?? null,
                'access_token'    => $data['access_token'],
                'business_id'     => $info['business']['id'] ?? null,
                'currency'        => $info['currency'] ?? 'INR',
                'timezone'        => $info['timezone_name'] ?? 'Asia/Kolkata',
                'account_status'  => $this->accountStatus($info['account_status'] ?? 1),
                'is_active'       => true,
                'is_default'      => $isDefault,
                'last_synced_at'  => now(),
            ]
        );
    }

    // ── Create campaign ───────────────────────────────────────────────────
    public function createCampaign(MetaAdAccount $account, array $data): MetaCampaign
    {
        // Accept the real Meta shape (an array of category strings). Tolerate the legacy boolean
        // and a bare string so older callers/payloads keep working.
        $categories = $this->normalizeSpecialAdCategories($data);

        $campaign = MetaCampaign::create([
            'company_id'              => $account->company_id,
            'meta_ad_account_id'      => $account->id,
            'created_by'              => auth()->id(),
            'name'                    => $data['name'],
            'objective'               => $data['objective'],
            'status'                  => 'PAUSED',
            'buying_type'             => $data['buying_type'] ?? 'AUCTION',
            'special_ad_category'     => count($categories) > 0,
            'special_ad_categories'   => $categories,
            'special_ad_category_country' => $data['special_ad_category_country'] ?? null,
            'spend_cap'               => $data['spend_cap'] ?? null,
        ]);

        // Push to Meta
        $payload = [
            'name'                  => $data['name'],
            'objective'             => $data['objective'],
            'status'                => 'PAUSED',
            'buying_type'           => $data['buying_type'] ?? 'AUCTION',
            'special_ad_categories' => count($categories) > 0 ? $categories : ['NONE'],
        ];
        if (!empty($data['special_ad_category_country'])) {
            $payload['special_ad_category_country'] = $data['special_ad_category_country'];
        }
        if (!empty($data['spend_cap'])) $payload['spend_cap'] = (int)($data['spend_cap'] * 100);

        $response = $this->post("/{$account->ad_account_id}/campaigns", $account->access_token, $payload);

        $campaign->update([
            'meta_campaign_id' => $response['id'] ?? null,
            'meta_response'    => $response,
        ]);

        return $campaign->fresh();
    }

    // ── Update campaign status (pause/resume/delete) ───────────────────────
    public function updateCampaignStatus(MetaCampaign $campaign, string $status): MetaCampaign
    {
        $account = $campaign->adAccount;
        $this->post("/{$campaign->meta_campaign_id}", $account->access_token, ['status' => $status]);
        $campaign->update(['status' => $status]);
        return $campaign->fresh();
    }

    // ── Edit campaign (push changes to Meta) ───────────────────────────────
    public function updateCampaign(MetaCampaign $campaign, array $data): MetaCampaign
    {
        $payload = [];
        if (array_key_exists('name', $data))      $payload['name'] = $data['name'];
        if (array_key_exists('spend_cap', $data)) $payload['spend_cap'] = $data['spend_cap'] ? (int) ($data['spend_cap'] * 100) : 0;
        if (!empty($data['special_ad_categories'])) {
            $payload['special_ad_categories'] = $this->normalizeSpecialAdCategories($data);
        }

        if ($payload && $campaign->meta_campaign_id) {
            $this->post("/{$campaign->meta_campaign_id}", $campaign->adAccount->access_token, $payload);
        }

        $campaign->update(array_filter([
            'name'                  => $data['name'] ?? null,
            'spend_cap'             => array_key_exists('spend_cap', $data) ? $data['spend_cap'] : null,
            'special_ad_categories' => $data['special_ad_categories'] ?? null,
        ], fn ($v) => $v !== null));

        return $campaign->fresh();
    }

    // ── Edit ad set (push changes to Meta) ────────────────────────────────
    public function updateAdSet(MetaAdSet $adSet, array $data): MetaAdSet
    {
        $account = $adSet->adAccount;

        // Allow re-pointing the ad set at a different saved audience, or an edited draft.
        if (!empty($data['audience_set_id'])) {
            $set = MetaAudienceSet::where('id', $data['audience_set_id'])->where('company_id', $adSet->company_id)->first();
            if ($set) $data['targeting'] = $this->compileTargetingSpec($set->toArray());
        } elseif (!empty($data['audience']) && is_array($data['audience'])) {
            $data['targeting'] = $this->compileTargetingSpec($data['audience']);
        }

        $payload = [];
        foreach (['name' => 'name', 'bid_amount' => 'bid_amount', 'daily_budget' => 'daily_budget', 'lifetime_budget' => 'lifetime_budget'] as $in => $out) {
            if (array_key_exists($in, $data) && $data[$in] !== null) {
                $payload[$out] = in_array($out, ['bid_amount', 'daily_budget', 'lifetime_budget'], true)
                    ? (int) ($data[$in] * 100)
                    : $data[$in];
            }
        }
        if (!empty($data['start_time'])) $payload['start_time'] = $data['start_time'];
        if (!empty($data['end_time']))   $payload['end_time']   = $data['end_time'];
        if (!empty($data['targeting']))  $payload['targeting']  = $data['targeting'];
        if (!empty($data['status']))     $payload['status']     = $data['status'];

        if ($payload && $adSet->meta_adset_id) {
            $this->post("/{$adSet->meta_adset_id}", $account->access_token, $payload);
        }

        $adSet->update(array_filter([
            'name'            => $data['name'] ?? null,
            'daily_budget'    => $data['daily_budget'] ?? null,
            'lifetime_budget' => $data['lifetime_budget'] ?? null,
            'bid_amount'      => $data['bid_amount'] ?? null,
            'start_time'      => $data['start_time'] ?? null,
            'end_time'        => $data['end_time'] ?? null,
            'targeting'       => $data['targeting'] ?? null,
            'status'          => $data['status'] ?? null,
            'audience_set_id' => $data['audience_set_id'] ?? null,
        ], fn ($v) => $v !== null));

        return $adSet->fresh();
    }

    // ── Duplicate (uses Meta's native /copies so history/learning carry sensibly) ──
    public function duplicateCampaign(MetaCampaign $campaign, bool $deep = true): MetaCampaign
    {
        $account = $campaign->adAccount;
        $res = $this->post("/{$campaign->meta_campaign_id}/copies", $account->access_token, [
            'deep_copy'         => $deep,
            'status_option'     => 'PAUSED',
            'rename_options'    => json_encode(['rename_suffix' => ' - Copy']),
        ]);
        $newId = $res['copied_campaign_id'] ?? $res['id'] ?? null;

        $copy = $campaign->replicate(['meta_campaign_id', 'meta_response', 'started_at', 'stopped_at']);
        $copy->name = $campaign->name . ' - Copy';
        $copy->status = 'PAUSED';
        $copy->meta_campaign_id = $newId;
        $copy->created_by = auth()->id();
        $copy->save();

        if ($deep && $newId) {
            $this->importCampaignChildrenFromMeta($copy);
        }
        return $copy->fresh();
    }

    public function duplicateAdSet(MetaAdSet $adSet): MetaAdSet
    {
        $account = $adSet->adAccount;
        $res = $this->post("/{$adSet->meta_adset_id}/copies", $account->access_token, [
            'deep_copy'     => true,
            'status_option' => 'PAUSED',
            'rename_options'=> json_encode(['rename_suffix' => ' - Copy']),
        ]);
        $newId = $res['copied_adset_id'] ?? $res['id'] ?? null;

        $copy = $adSet->replicate(['meta_adset_id', 'meta_response']);
        $copy->name = $adSet->name . ' - Copy';
        $copy->status = 'PAUSED';
        $copy->meta_adset_id = $newId;
        $copy->save();
        return $copy->fresh();
    }

    public function deleteCampaignRemote(MetaCampaign $campaign): void
    {
        if ($campaign->meta_campaign_id) {
            $this->deleteGraph("/{$campaign->meta_campaign_id}", $campaign->adAccount->access_token);
        }
    }

    /** Pull the ad sets + ads Meta created under a deep-copied campaign back into our tables. */
    private function importCampaignChildrenFromMeta(MetaCampaign $campaign): void
    {
        $account = $campaign->adAccount;
        $adSets = $this->graph()->getAllPages("/{$campaign->meta_campaign_id}/adsets", $account->access_token, [
            'fields' => 'id,name,status,optimization_goal,billing_event,bid_strategy,daily_budget,lifetime_budget,targeting,start_time,end_time',
        ]);
        foreach ($adSets as $row) {
            MetaAdSet::updateOrCreate(
                ['meta_adset_id' => $row['id']],
                [
                    'company_id'         => $campaign->company_id,
                    'meta_campaign_id'   => $campaign->id,
                    'meta_ad_account_id' => $account->id,
                    'name'               => $row['name'] ?? 'Ad set',
                    'status'             => $row['status'] ?? 'PAUSED',
                    'optimization_goal'  => $row['optimization_goal'] ?? 'LEAD_GENERATION',
                    'billing_event'      => $row['billing_event'] ?? 'IMPRESSIONS',
                    'bid_strategy'       => $row['bid_strategy'] ?? 'LOWEST_COST_WITHOUT_CAP',
                    'daily_budget'       => isset($row['daily_budget']) ? $row['daily_budget'] / 100 : null,
                    'lifetime_budget'    => isset($row['lifetime_budget']) ? $row['lifetime_budget'] / 100 : null,
                    'targeting'          => $row['targeting'] ?? null,
                    'start_time'         => $row['start_time'] ?? null,
                    'end_time'           => $row['end_time'] ?? null,
                ]
            );
        }
    }

    // ── Create ad set ──────────────────────────────────────────────────────
    public function createAdSet(MetaCampaign $campaign, array $data): MetaAdSet
    {
        $account = $campaign->adAccount;

        // One-click reuse: an audience set supplies the whole targeting spec. An explicit
        // `targeting` in the payload still wins (the "customize a copy" flow sends the edited spec
        // directly), so this only fills the gap when the caller passed a set id and nothing else.
        $audienceSet = null;
        if (!empty($data['audience_set_id'])) {
            $audienceSet = MetaAudienceSet::where('id', $data['audience_set_id'])
                ->where('company_id', $campaign->company_id)
                ->first();
            if ($audienceSet && empty($data['targeting'])) {
                $data['targeting'] = $this->compileTargetingSpec($audienceSet->toArray());
            }
            if ($audienceSet && empty($data['placements']) && !empty($audienceSet->placements)) {
                $data['placements'] = $audienceSet->placements;
            }
        }

        // "Customize a copy": the builder sends the edited audience in our normalized shape and we
        // compile it here — same path the reach estimate uses, so what they previewed is what ships.
        if (empty($data['targeting']) && !empty($data['audience']) && is_array($data['audience'])) {
            $data['targeting'] = $this->compileTargetingSpec($data['audience']);
            if (empty($data['placements']) && !empty($data['audience']['placements'])) {
                $data['placements'] = $data['audience']['placements'];
            }
        }

        if (empty($data['targeting'])) {
            throw new \InvalidArgumentException('An ad set needs a targeting spec or a valid audience set.');
        }

        $adSet = MetaAdSet::create([
            'company_id'           => $campaign->company_id,
            'meta_campaign_id'     => $campaign->id,
            'meta_ad_account_id'   => $account->id,
            'audience_template_id' => $data['audience_template_id'] ?? null,
            'audience_set_id'      => $audienceSet?->id,
            'name'                 => $data['name'],
            'status'               => 'PAUSED',
            'optimization_goal'    => $data['optimization_goal'] ?? 'LEAD_GENERATION',
            'billing_event'        => $data['billing_event'] ?? 'IMPRESSIONS',
            'bid_strategy'         => $data['bid_strategy'] ?? 'LOWEST_COST_WITHOUT_CAP',
            'daily_budget'         => $data['daily_budget'] ?? null,
            'lifetime_budget'      => $data['lifetime_budget'] ?? null,
            'bid_amount'           => $data['bid_amount'] ?? null,
            'targeting'            => $data['targeting'],
            'placements'           => $data['placements'] ?? null,
            'start_time'           => $data['start_time'] ?? null,
            'end_time'             => $data['end_time'] ?? null,
        ]);

        $payload = [
            'name'              => $data['name'],
            'campaign_id'       => $campaign->meta_campaign_id,
            'optimization_goal' => $data['optimization_goal'] ?? 'LEAD_GENERATION',
            'billing_event'     => $data['billing_event'] ?? 'IMPRESSIONS',
            'bid_strategy'      => $data['bid_strategy'] ?? 'LOWEST_COST_WITHOUT_CAP',
            'targeting'         => $data['targeting'],
            'status'            => 'PAUSED',
        ];

        if (!empty($data['daily_budget']))    $payload['daily_budget']    = (int)($data['daily_budget'] * 100);
        if (!empty($data['lifetime_budget'])) $payload['lifetime_budget'] = (int)($data['lifetime_budget'] * 100);
        if (!empty($data['bid_amount']))      $payload['bid_amount']      = (int)($data['bid_amount'] * 100);
        if (!empty($data['start_time']))      $payload['start_time']      = $data['start_time'];
        if (!empty($data['end_time']))        $payload['end_time']        = $data['end_time'];

        // Placements: fold the manual placement selection into the targeting spec Meta expects.
        // `automatic` (or an empty selection) leaves Meta to optimise placements itself.
        if (!empty($data['placements']) && empty($data['placements']['automatic'])) {
            $payload['targeting'] = array_merge($payload['targeting'], $this->placementsToTargeting($data['placements']));
        }

        $response = $this->post("/{$account->ad_account_id}/adsets", $account->access_token, $payload);
        $adSet->update(['meta_adset_id' => $response['id'] ?? null, 'meta_response' => $response]);

        // A reused audience set gets a usage bump so the picker can surface "most used" first.
        if ($audienceSet) {
            $audienceSet->forceFill([
                'use_count'    => $audienceSet->use_count + 1,
                'last_used_at' => now(),
            ])->save();
        }

        return $adSet->fresh();
    }

    // ── Upload image to Meta ───────────────────────────────────────────────
    public function uploadImage(MetaAdAccount $account, string $filePath, string $filename): MetaMediaLibrary
    {
        $media = MetaMediaLibrary::create([
            'company_id'         => $account->company_id,
            'meta_ad_account_id' => $account->id,
            'uploaded_by'        => auth()->id(),
            'type'               => 'image',
            'original_filename'  => $filename,
            'storage_path'       => $filePath,
            'upload_status'      => 'uploading',
        ]);

        try {
            $response = Http::withToken($account->access_token)
                ->attach('filename', file_get_contents($filePath), $filename)
                ->post("{$this->graphUploadUrl()}/{$account->ad_account_id}/adimages")
                ->json();

            $hash = collect($response['images'] ?? [])->first()['hash'] ?? null;

            $media->update([
                'meta_image_hash' => $hash,
                'upload_status'   => $hash ? 'ready' : 'failed',
                'upload_error'    => $hash ? null : 'Failed to get image hash from Meta.',
            ]);
        } catch (\Exception $e) {
            $media->update(['upload_status' => 'failed', 'upload_error' => $e->getMessage()]);
        }

        return $media->fresh();
    }

    // ── Upload video to Meta ───────────────────────────────────────────────
    public function uploadVideo(MetaAdAccount $account, string $filePath, string $filename, string $title): MetaMediaLibrary
    {
        $media = MetaMediaLibrary::create([
            'company_id'         => $account->company_id,
            'meta_ad_account_id' => $account->id,
            'uploaded_by'        => auth()->id(),
            'type'               => 'video',
            'original_filename'  => $filename,
            'storage_path'       => $filePath,
            'upload_status'      => 'uploading',
        ]);

        try {
            $response = Http::withToken($account->access_token)
                ->attach('source', file_get_contents($filePath), $filename)
                ->post("{$this->graphUploadUrl()}/{$account->ad_account_id}/advideos", ['title' => $title])
                ->json();

            $media->update([
                'meta_video_id' => $response['id'] ?? null,
                'upload_status' => isset($response['id']) ? 'ready' : 'failed',
                'upload_error'  => isset($response['id']) ? null : 'Failed to upload video.',
            ]);
        } catch (\Exception $e) {
            $media->update(['upload_status' => 'failed', 'upload_error' => $e->getMessage()]);
        }

        return $media->fresh();
    }

    // ── Create ad creative ─────────────────────────────────────────────────
    public function createCreative(MetaAdAccount $account, array $data): MetaAdCreative
    {
        // A lead-ad creative carries a lead form instead of a destination URL — resolve it here so
        // the spec builders can drop `lead_gen_form_id` into the call-to-action.
        $leadForm = null;
        if (!empty($data['lead_form_id'])) {
            $leadForm = \App\Models\MetaLeadForm::where('id', $data['lead_form_id'])
                ->where('company_id', $account->company_id)->first();
            $data['lead_gen_form_id'] = $leadForm?->meta_form_id;
        }

        $creative = MetaAdCreative::create([
            'company_id'         => $account->company_id,
            'meta_ad_account_id' => $account->id,
            'name'               => $data['name'] ?? 'Creative',
            'format'             => $data['format'],
            'page_id'            => $data['page_id'] ?? $account->page_id,
            'primary_text'       => $data['primary_text'],
            'headline'           => $data['headline'] ?? null,
            'description'        => $data['description'] ?? null,
            'call_to_action'     => $data['call_to_action'] ?? ($leadForm ? 'SIGN_UP' : 'LEARN_MORE'),
            'destination_url'    => $data['destination_url'] ?? null,
            'image_id'           => $data['image_id'] ?? null,
            'video_id'           => $data['video_id'] ?? null,
            'lead_form_id'       => $leadForm?->id,
            'carousel_cards'     => $data['carousel_cards'] ?? null,
        ]);

        // Build object_story_spec based on format
        $spec = match ($data['format']) {
            'image'    => $this->buildImageSpec($data, $account),
            'video'    => $this->buildVideoSpec($data, $account),
            'carousel' => $this->buildCarouselSpec($data, $account),
            default    => throw new \Exception("Unknown format: {$data['format']}"),
        };

        $response = $this->post("/{$account->ad_account_id}/adcreatives", $account->access_token, [
            'name'               => $data['name'] ?? 'Creative',
            'object_story_spec'  => $spec,
        ]);

        $creative->update(['meta_creative_id' => $response['id'] ?? null, 'meta_response' => $response]);
        return $creative->fresh();
    }

    // ── Create and publish ad ──────────────────────────────────────────────
    public function createAd(MetaAdSet $adSet, MetaAdCreative $creative, array $data): MetaAd
    {
        $account = $adSet->adAccount;
        $status  = $data['publish'] ? 'ACTIVE' : 'PAUSED';

        $ad = MetaAd::create([
            'company_id'         => $account->company_id,
            'meta_ad_set_id'     => $adSet->id,
            'meta_ad_creative_id'=> $creative->id,
            'name'               => $data['name'],
            'status'             => 'PAUSED',
        ]);

        $response = $this->post("/{$account->ad_account_id}/ads", $account->access_token, [
            'name'       => $data['name'],
            'adset_id'   => $adSet->meta_adset_id,
            'creative'   => ['creative_id' => $creative->meta_creative_id],
            'status'     => $status,
        ]);

        $ad->update([
            'meta_ad_id'    => $response['id'] ?? null,
            'status'        => $status,
            'meta_response' => $response,
            'published_at'  => $data['publish'] ? now() : null,
        ]);

        return $ad->fresh();
    }

    // ── Update ad status ───────────────────────────────────────────────────
    public function updateAdStatus(MetaAd $ad, string $status): MetaAd
    {
        $account = $ad->adSet->adAccount;
        $this->post("/{$ad->meta_ad_id}", $account->access_token, ['status' => $status]);
        $ad->update(['status' => $status]);
        return $ad->fresh();
    }

    // ── Sync insights for a campaign ──────────────────────────────────────
    public function syncInsights(MetaCampaign $campaign, string $dateStart, string $dateStop): void
    {
        $account  = $campaign->adAccount;
        $rows = $this->graph()->getAllPages("/{$campaign->meta_campaign_id}/insights", $account->access_token, [
            'fields'       => 'impressions,reach,frequency,clicks,unique_clicks,ctr,cpc,cpm,spend,actions,action_values,'
                            . 'outbound_clicks,video_thruplay_watched_actions,video_play_actions,'
                            . 'video_p25_watched_actions,video_p50_watched_actions,video_p100_watched_actions',
            'date_preset'  => 'custom',
            'time_range'   => json_encode(['since' => $dateStart, 'until' => $dateStop]),
            'time_increment'=> 1,
        ]);

        foreach ($rows as $row) {
            $leads     = $this->extractAction($row['actions'] ?? [], 'lead');
            $purchases = $this->extractAction($row['actions'] ?? [], 'purchase');
            $purchaseValue = $this->extractActionValue($row['action_values'] ?? [], 'purchase');
            $spend     = (float) ($row['spend'] ?? 0);
            // "Video views" = video_play_actions (a real play started), not the p25 completion bucket.
            $videoPlays = $this->extractAction($row['video_play_actions'] ?? [], 'video_view');

            MetaInsight::updateOrCreate(
                ['object_type' => 'campaign', 'object_id' => $campaign->id, 'date' => $row['date_start']],
                [
                    'company_id'         => $campaign->company_id,
                    'impressions'        => $row['impressions'] ?? 0,
                    'reach'              => $row['reach'] ?? 0,
                    'frequency'          => $row['frequency'] ?? 0,
                    'clicks'             => $row['clicks'] ?? 0,
                    'unique_clicks'      => $row['unique_clicks'] ?? 0,
                    'link_clicks'        => $this->extractAction($row['outbound_clicks'] ?? [], 'outbound_click'),
                    'ctr'                => $row['ctr'] ?? 0,
                    'cpc'                => $row['cpc'] ?? 0,
                    'cpm'                => $row['cpm'] ?? 0,
                    'spend'              => $spend,
                    'leads'              => $leads,
                    'cost_per_lead'      => $leads > 0 ? round($spend / $leads, 4) : 0,
                    'purchases'          => $purchases,
                    'purchase_value'     => $purchaseValue,
                    'roas'               => $purchaseValue > 0 && $spend > 0 ? round($purchaseValue / $spend, 4) : 0,
                    'video_views'        => $videoPlays,
                    'thruplays'          => $this->extractAction($row['video_thruplay_watched_actions'] ?? [], 'video_view'),
                    'video_views_25pct'  => $this->extractAction($row['video_p25_watched_actions'] ?? [], 'video_view'),
                    'video_views_50pct'  => $this->extractAction($row['video_p50_watched_actions'] ?? [], 'video_view'),
                    'video_views_100pct' => $this->extractAction($row['video_p100_watched_actions'] ?? [], 'video_view'),
                    'raw_data'           => $row,
                ]
            );
        }
    }

    // ── Sync ad review status (called by webhook or polling job) ─────────
    public function syncAdReviewStatus(MetaAd $ad): MetaAd
    {
        $account  = $ad->adSet->adAccount;
        $response = $this->get("/{$ad->meta_ad_id}", $account->access_token, [
            'fields' => 'effective_status,review_feedback_summary,status',
        ]);

        $reviewStatus     = $this->parseReviewStatus($response);
        $rejectionReason  = $this->parseRejectionReason($response['review_feedback_summary'] ?? []);

        $ad->update([
            'effective_status' => $response['effective_status'] ?? null,
            'status'           => $response['status'] ?? $ad->status,
            'review_status'    => $reviewStatus,
            'rejection_reason' => $rejectionReason,
        ]);

        return $ad->fresh();
    }

    // ── List campaigns from Meta ───────────────────────────────────────────
    public function listCampaignsFromMeta(MetaAdAccount $account): array
    {
        return $this->get("/{$account->ad_account_id}/campaigns", $account->access_token, [
            'fields' => 'id,name,status,objective,spend_cap,effective_status',
            'limit'  => 100,
        ])['data'] ?? [];
    }

    // ── Get audience template ──────────────────────────────────────────────
    public function getAudienceTemplates(): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\MetaAudienceTemplate::where('is_active', true)->orderBy('sort_order')->get();
    }

    // ── Audience sets ─────────────────────────────────────────────────────

    /**
     * Compile our normalized audience-set shape into the Meta `targeting` object. Accepts the
     * model as an array so it works for a saved MetaAudienceSet, an unsaved draft from the form,
     * or a system template row (its `targeting_json` is used as the flexible_spec base).
     */
    public function compileTargetingSpec(array $set): array
    {
        $spec = [
            'age_min' => (int) ($set['age_min'] ?? 18),
            'age_max' => (int) ($set['age_max'] ?? 65),
        ];

        $genders = $set['genders'] ?? 'all';
        if ($genders === 'male')   $spec['genders'] = [1];
        if ($genders === 'female') $spec['genders'] = [2];

        // Geo — default to the ad account's country only when nothing at all was provided, so a
        // spec is never sent to Meta with an empty/global location (which it rejects).
        $geo = $set['geo_locations'] ?? null;
        if (is_array($geo) && $this->hasAnyGeo($geo)) {
            $spec['geo_locations'] = array_filter([
                'countries'        => $geo['countries'] ?? null,
                'regions'          => $geo['regions'] ?? null,
                'cities'           => $geo['cities'] ?? null,
                'custom_locations' => $geo['custom_locations'] ?? null,
                'location_types'   => $geo['location_types'] ?? null,
            ], fn ($v) => !empty($v));
        }

        // Interests + behaviours go into a single flexible_spec group (an AND across groups, OR
        // within one). A saved set can also carry its own verbatim flexible_spec for advanced cases.
        if (!empty($set['flexible_spec']) && is_array($set['flexible_spec'])) {
            $spec['flexible_spec'] = $set['flexible_spec'];
        } else {
            $group = array_filter([
                'interests' => $this->idNamePairs($set['interests'] ?? []),
                'behaviors' => $this->idNamePairs($set['behaviors'] ?? []),
            ], fn ($v) => !empty($v));
            if ($group) $spec['flexible_spec'] = [$group];
        }

        $exclusions = array_filter([
            'interests' => $this->idNamePairs($set['exclusions']['interests'] ?? []),
            'behaviors' => $this->idNamePairs($set['exclusions']['behaviors'] ?? []),
        ], fn ($v) => !empty($v));
        if ($exclusions) $spec['exclusions'] = $exclusions;

        if (!empty($set['custom_audiences'])) {
            $spec['custom_audiences'] = $this->idOnly($set['custom_audiences']);
        }
        if (!empty($set['excluded_custom_audiences'])) {
            $spec['excluded_custom_audiences'] = $this->idOnly($set['excluded_custom_audiences']);
        }
        if (!empty($set['locales'])) {
            $spec['locales'] = array_values(array_map('intval', $set['locales']));
        }

        if (!empty($set['placements']) && empty($set['placements']['automatic'])) {
            $spec = array_merge($spec, $this->placementsToTargeting($set['placements']));
        }

        return $spec;
    }

    /**
     * Ask Meta how many people a targeting spec would reach. Returns
     * `['ready' => bool, 'users_lower_bound' => int, 'users_upper_bound' => int]`.
     */
    public function estimateReach(MetaAdAccount $account, array $targetingSpec, string $optimizationGoal = 'REACH'): array
    {
        $response = $this->get("/{$account->ad_account_id}/delivery_estimate", $account->access_token, [
            'optimization_goal' => $optimizationGoal,
            'targeting_spec'    => json_encode($targetingSpec),
        ]);

        $row = $response['data'][0] ?? [];
        return [
            'ready'             => (bool) ($row['estimate_ready'] ?? false),
            'users_lower_bound' => (int) ($row['estimate_mau_lower_bound'] ?? $row['users_lower_bound'] ?? 0),
            'users_upper_bound' => (int) ($row['estimate_mau_upper_bound'] ?? $row['users_upper_bound'] ?? 0),
            'daily_outcomes'    => $row['daily_outcomes_curve'] ?? [],
        ];
    }

    /**
     * Typeahead for the audience-set builder: search Meta's targeting catalog.
     * `$type` is one of adinterest | adTargetingCategory | adgeolocation | adlocale | adworkemployer.
     */
    public function searchTargeting(MetaAdAccount $account, string $query, string $type = 'adinterest'): array
    {
        $params = ['type' => $type, 'q' => $query, 'limit' => 25];
        if ($type === 'adTargetingCategory') {
            $params['class'] = 'behaviors';
            unset($params['q']);
        }
        return $this->get('/search', $account->access_token, $params)['data'] ?? [];
    }

    // ─── audience-set helpers ───

    private function hasAnyGeo(array $geo): bool
    {
        foreach (['countries', 'regions', 'cities', 'custom_locations'] as $k) {
            if (!empty($geo[$k])) return true;
        }
        return false;
    }

    /** Normalise a list of `{id,name}` (or bare ids) to Meta's `[{id,name}]` interest/behaviour shape. */
    private function idNamePairs($list): array
    {
        return collect(is_array($list) ? $list : [])
            ->map(fn ($x) => is_array($x)
                ? array_filter(['id' => (string) ($x['id'] ?? ''), 'name' => $x['name'] ?? null])
                : ['id' => (string) $x])
            ->filter(fn ($x) => !empty($x['id']))
            ->values()->all();
    }

    private function idOnly($list): array
    {
        return collect(is_array($list) ? $list : [])
            ->map(fn ($x) => ['id' => (string) (is_array($x) ? ($x['id'] ?? '') : $x)])
            ->filter(fn ($x) => $x['id'] !== '')
            ->values()->all();
    }

    /** Turn our `placements` object into the publisher/position keys Meta's targeting spec wants. */
    private function placementsToTargeting(array $placements): array
    {
        $out = [];
        $map = [
            'publisher_platforms'      => 'publisher_platforms',
            'facebook_positions'       => 'facebook_positions',
            'instagram_positions'      => 'instagram_positions',
            'audience_network_positions' => 'audience_network_positions',
            'messenger_positions'      => 'messenger_positions',
            'device_platforms'         => 'device_platforms',
        ];
        foreach ($map as $from => $to) {
            if (!empty($placements[$from])) $out[$to] = array_values($placements[$from]);
        }
        if (empty($out['publisher_platforms'])) $out['publisher_platforms'] = ['facebook', 'instagram'];
        return $out;
    }

    /** Coerce whatever `special_ad_categor*` the caller sent into a clean array of valid categories. */
    private function normalizeSpecialAdCategories(array $data): array
    {
        $raw = $data['special_ad_categories']
            ?? ($data['special_ad_category_type'] ?? null)
            ?? (!empty($data['special_ad_category']) ? [] : null);

        $list = is_array($raw) ? $raw : ($raw ? [$raw] : []);
        return collect($list)
            ->map(fn ($c) => strtoupper((string) $c))
            ->filter(fn ($c) => in_array($c, self::SPECIAL_AD_CATEGORIES, true))
            ->unique()->values()->all();
    }

    // ────────────────── Private helpers ──────────────────────────────────

    private function buildImageSpec(array $data, MetaAdAccount $account): array
    {
        $media  = \App\Models\MetaMediaLibrary::findOrFail($data['image_id']);
        return [
            'page_id' => $data['page_id'] ?? $account->page_id,
            'link_data' => [
                'image_hash'    => $media->meta_image_hash,
                'message'       => $data['primary_text'],
                'link'          => $data['destination_url'] ?? 'https://fb.com/' . ($data['page_id'] ?? $account->page_id),
                'name'          => $data['headline'] ?? '',
                'description'   => $data['description'] ?? '',
                'call_to_action'=> $this->ctaSpec($data),
            ],
        ];
    }

    private function buildVideoSpec(array $data, MetaAdAccount $account): array
    {
        $media = \App\Models\MetaMediaLibrary::findOrFail($data['video_id']);
        return [
            'page_id' => $data['page_id'] ?? $account->page_id,
            'video_data' => [
                'video_id'           => $media->meta_video_id,
                'image_url'          => $data['video_thumbnail_url'] ?? $media->meta_thumbnail_url,
                'message'            => $data['primary_text'],
                'title'              => $data['headline'] ?? '',
                'call_to_action'     => $this->ctaSpec($data),
            ],
        ];
    }

    /** The call_to_action object — carries a lead form when this is a lead ad, else the click link. */
    private function ctaSpec(array $data): array
    {
        $type = $data['call_to_action'] ?? (!empty($data['lead_gen_form_id']) ? 'SIGN_UP' : 'LEARN_MORE');
        $value = !empty($data['lead_gen_form_id'])
            ? ['lead_gen_form_id' => (string) $data['lead_gen_form_id']]
            : ['link' => $data['destination_url'] ?? ''];
        return ['type' => $type, 'value' => $value];
    }

    private function buildCarouselSpec(array $data, MetaAdAccount $account): array
    {
        $cards = collect($data['carousel_cards'])->map(function ($card) {
            $media = \App\Models\MetaMediaLibrary::find($card['image_id']);
            return [
                'link'          => $card['url'] ?? '',
                'name'          => $card['headline'] ?? '',
                'description'   => $card['description'] ?? '',
                'image_hash'    => $media?->meta_image_hash,
                'call_to_action'=> ['type' => $card['cta'] ?? 'LEARN_MORE', 'value' => ['link' => $card['url'] ?? '']],
            ];
        })->toArray();

        return [
            'page_id' => $data['page_id'] ?? $account->page_id,
            'link_data' => [
                'message'        => $data['primary_text'],
                'link'           => $data['destination_url'] ?? 'https://www.facebook.com',
                'child_attachments'=> $cards,
                'multi_share_optimized' => true,
            ],
        ];
    }

    // Graph calls go through MetaGraphClient (retries, pagination, typed MetaApiException). These
    // stay as thin private forwarders so the many call sites above don't each new-up a client.
    private function graph(): MetaGraphClient
    {
        return $this->graph ??= app(MetaGraphClient::class);
    }

    private function get(string $path, string $token, array $params = []): array
    {
        return $this->graph()->get(ltrim($path, '/'), $token, $params);
    }

    private function post(string $path, string $token, array $data = []): array
    {
        return $this->graph()->post(ltrim($path, '/'), $token, $data);
    }

    private function deleteGraph(string $path, string $token, array $params = []): array
    {
        return $this->graph()->delete(ltrim($path, '/'), $token, $params);
    }

    private function extractAction(array $actions, string $type): int
    {
        // Parens matter: `(int) $x['value'] ?? 0` binds as `((int) $x['value']) ?? 0` — the `?? 0`
        // is dead (a cast is never null) and `firstWhere` returning null makes `null['value']` a
        // warning on every miss. Coalesce the offset access FIRST, then cast.
        return (int) (collect($actions)->firstWhere('action_type', $type)['value'] ?? 0);
    }

    private function extractActionValue(array $values, string $type): float
    {
        return (float) (collect($values)->firstWhere('action_type', $type)['value'] ?? 0);
    }

    private function parseReviewStatus(array $response): string
    {
        $effective = $response['effective_status'] ?? '';
        return match ($effective) {
            'ACTIVE'          => 'APPROVED',
            'DISAPPROVED'     => 'REJECTED',
            'PENDING_REVIEW'  => 'IN_REVIEW',
            'IN_PROCESS'      => 'IN_REVIEW',
            default           => 'PENDING',
        };
    }

    private function parseRejectionReason(array $feedback): ?string
    {
        if (empty($feedback)) return null;
        return collect($feedback)->map(fn($f) => $f['title'] ?? '')->implode(', ');
    }

    private function accountStatus(int $code): string
    {
        return match ($code) {
            1 => 'active', 2 => 'disabled', 3 => 'unsettled',
            7 => 'pending_risk_review', 9 => 'in_grace_period',
            default => 'active',
        };
    }
}
