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

class MetaAdsService
{
    private string $graphUrl = 'https://graph.facebook.com/v21.0';

    // ── Connect ad account ────────────────────────────────────────────────
    public function connectAdAccount(int $companyId, array $data): MetaAdAccount
    {
        // Validate token by fetching account info
        $info = $this->get("/{$data['ad_account_id']}", $data['access_token'], [
            'fields' => 'name,currency,timezone_name,account_status,business',
        ]);

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
        $campaign = MetaCampaign::create([
            'company_id'          => $account->company_id,
            'meta_ad_account_id'  => $account->id,
            'created_by'          => auth()->id(),
            'name'                => $data['name'],
            'objective'           => $data['objective'],
            'status'              => 'PAUSED',
            'buying_type'         => $data['buying_type'] ?? 'AUCTION',
            'special_ad_category' => $data['special_ad_category'] ?? false,
            'spend_cap'           => $data['spend_cap'] ?? null,
        ]);

        // Push to Meta
        $payload = [
            'name'                  => $data['name'],
            'objective'             => $data['objective'],
            'status'                => 'PAUSED',
            'buying_type'           => $data['buying_type'] ?? 'AUCTION',
            'special_ad_categories' => $data['special_ad_category'] ? [$data['special_ad_category_type'] ?? 'NONE'] : ['NONE'],
        ];
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

    // ── Create ad set ──────────────────────────────────────────────────────
    public function createAdSet(MetaCampaign $campaign, array $data): MetaAdSet
    {
        $account = $campaign->adAccount;

        $adSet = MetaAdSet::create([
            'company_id'           => $campaign->company_id,
            'meta_campaign_id'     => $campaign->id,
            'meta_ad_account_id'   => $account->id,
            'audience_template_id' => $data['audience_template_id'] ?? null,
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

        // Placements
        if (!empty($data['placements'])) {
            $payload['targeting']['publisher_platforms'] = $data['placements']['platforms'] ?? ['facebook','instagram'];
        }

        $response = $this->post("/{$account->ad_account_id}/adsets", $account->access_token, $payload);
        $adSet->update(['meta_adset_id' => $response['id'] ?? null, 'meta_response' => $response]);

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
                ->post("{$this->graphUrl}/{$account->ad_account_id}/adimages")
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
                ->post("{$this->graphUrl}/{$account->ad_account_id}/advideos", ['title' => $title])
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
        $creative = MetaAdCreative::create([
            'company_id'         => $account->company_id,
            'meta_ad_account_id' => $account->id,
            'name'               => $data['name'] ?? 'Creative',
            'format'             => $data['format'],
            'page_id'            => $data['page_id'] ?? $account->page_id,
            'primary_text'       => $data['primary_text'],
            'headline'           => $data['headline'] ?? null,
            'description'        => $data['description'] ?? null,
            'call_to_action'     => $data['call_to_action'] ?? 'LEARN_MORE',
            'destination_url'    => $data['destination_url'] ?? null,
            'image_id'           => $data['image_id'] ?? null,
            'video_id'           => $data['video_id'] ?? null,
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
        $response = $this->get("/{$campaign->meta_campaign_id}/insights", $account->access_token, [
            'fields'       => 'impressions,reach,clicks,unique_clicks,ctr,cpc,cpm,spend,actions,action_values,video_p25_watched_actions,video_p50_watched_actions,video_p100_watched_actions',
            'date_preset'  => 'custom',
            'time_range'   => json_encode(['since' => $dateStart, 'until' => $dateStop]),
            'time_increment'=> 1,
        ]);

        foreach ($response['data'] ?? [] as $row) {
            $leads     = $this->extractAction($row['actions'] ?? [], 'lead');
            $purchases = $this->extractAction($row['actions'] ?? [], 'purchase');
            $purchaseValue = $this->extractActionValue($row['action_values'] ?? [], 'purchase');

            MetaInsight::updateOrCreate(
                ['object_type' => 'campaign', 'object_id' => $campaign->id, 'date' => $row['date_start']],
                [
                    'company_id'         => $campaign->company_id,
                    'impressions'        => $row['impressions'] ?? 0,
                    'reach'              => $row['reach'] ?? 0,
                    'clicks'             => $row['clicks'] ?? 0,
                    'unique_clicks'      => $row['unique_clicks'] ?? 0,
                    'ctr'                => $row['ctr'] ?? 0,
                    'cpc'                => $row['cpc'] ?? 0,
                    'cpm'                => $row['cpm'] ?? 0,
                    'spend'              => $row['spend'] ?? 0,
                    'leads'              => $leads,
                    'purchases'          => $purchases,
                    'purchase_value'     => $purchaseValue,
                    'roas'               => $purchaseValue > 0 && $row['spend'] > 0
                                           ? round($purchaseValue / $row['spend'], 4) : 0,
                    'video_views'        => $this->extractAction($row['video_p25_watched_actions'] ?? [], 'video_view'),
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

    // ────────────────── Private helpers ──────────────────────────────────

    private function buildImageSpec(array $data, MetaAdAccount $account): array
    {
        $media  = \App\Models\MetaMediaLibrary::findOrFail($data['image_id']);
        return [
            'page_id' => $data['page_id'] ?? $account->page_id,
            'link_data' => [
                'image_hash'    => $media->meta_image_hash,
                'message'       => $data['primary_text'],
                'link'          => $data['destination_url'] ?? 'https://www.facebook.com',
                'name'          => $data['headline'] ?? '',
                'description'   => $data['description'] ?? '',
                'call_to_action'=> ['type' => $data['call_to_action'] ?? 'LEARN_MORE', 'value' => ['link' => $data['destination_url'] ?? '']],
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
                'call_to_action'     => ['type' => $data['call_to_action'] ?? 'LEARN_MORE', 'value' => ['link' => $data['destination_url'] ?? '']],
            ],
        ];
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

    private function get(string $path, string $token, array $params = []): array
    {
        $response = Http::withToken($token)->get($this->graphUrl . $path, $params);
        if ($response->failed()) {
            $err = $response->json('error.message') ?? 'Meta API error';
            throw new \Exception($err);
        }
        return $response->json();
    }

    private function post(string $path, string $token, array $data = []): array
    {
        $response = Http::withToken($token)->post($this->graphUrl . $path, $data);
        if ($response->failed()) {
            $err = $response->json('error.message') ?? 'Meta API error';
            throw new \Exception($err);
        }
        return $response->json();
    }

    private function extractAction(array $actions, string $type): int
    {
        return (int) collect($actions)->firstWhere('action_type', $type)['value'] ?? 0;
    }

    private function extractActionValue(array $values, string $type): float
    {
        return (float) collect($values)->firstWhere('action_type', $type)['value'] ?? 0;
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
