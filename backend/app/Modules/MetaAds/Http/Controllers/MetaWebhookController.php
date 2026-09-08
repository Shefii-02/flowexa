<?php

namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MetaAd;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Modules\MetaAds\Services\MetaLeadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly MetaAdsService $ads,
        private readonly MetaLeadService $leads,
    ) {}

    /** GET — Meta's subscription verification handshake. */
    public function verify(Request $request): mixed
    {
        $expected = config('services.meta_ads.webhook_verify_token');
        if ($request->query('hub_mode') === 'subscribe'
            && $expected
            && hash_equals((string) $expected, (string) $request->query('hub_verify_token'))) {
            return response($request->query('hub_challenge'), 200);
        }
        return response('Forbidden', 403);
    }

    /** POST — inbound events: `leadgen` (lead ads) and `ad_review` (policy decisions). */
    public function handle(Request $request): mixed
    {
        if (!$this->signatureValid($request)) {
            Log::warning('MetaWebhookController: rejected webhook with bad signature');
            return response('invalid signature', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if ($field === 'leadgen') {
                    $this->leads->ingestWebhookLead($value);
                } elseif ($field === 'ad_review' || $field === 'ads_review') {
                    $adId = $value['ad_id'] ?? null;
                    if ($adId && ($ad = MetaAd::where('meta_ad_id', $adId)->first())) {
                        $this->ads->syncAdReviewStatus($ad);
                    }
                }
            }
        }

        return response('OK', 200);
    }

    /**
     * Validate `X-Hub-Signature-256` against the app secret. When no app secret is configured the
     * check is skipped (dev / not-yet-set-up) rather than blocking every event.
     */
    private function signatureValid(Request $request): bool
    {
        $secret = config('services.meta_ads.app_secret');
        if (!$secret) return true;

        $header = $request->header('X-Hub-Signature-256', '');
        if (!str_starts_with($header, 'sha256=')) return false;

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $header);
    }
}
