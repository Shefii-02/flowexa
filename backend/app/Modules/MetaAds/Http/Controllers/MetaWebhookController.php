<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

class MetaWebhookController extends Controller
{
    public function handle(Request $request): mixed
    {
        // Ad status change webhook from Meta
        $entries = $request->input('entry', []);
        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] === 'ad_review') {
                    $adId = $change['value']['ad_id'] ?? null;
                    if ($adId) {
                        $ad = MetaAd::where('meta_ad_id', $adId)->first();
                        if ($ad) app(MetaAdsService::class)->syncAdReviewStatus($ad);
                    }
                }
            }
        }
        return response('OK', 200);
    }
}
