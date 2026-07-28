<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

class MetaInsightController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function overview(): JsonResponse {
        $cid  = auth()->user()->company_id;
        $from = request('from', now()->subDays(30)->toDateString());
        $to   = request('to',   now()->toDateString());

        $stats = MetaInsight::where('company_id', $cid)
            ->where('object_type', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('SUM(impressions) as impressions, SUM(reach) as reach, SUM(clicks) as clicks, SUM(spend) as spend, SUM(leads) as leads, SUM(purchases) as purchases, SUM(purchase_value) as purchase_value')
            ->first();

        $daily = MetaInsight::where('company_id', $cid)
            ->where('object_type', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date, SUM(spend) as spend, SUM(clicks) as clicks, SUM(leads) as leads, SUM(impressions) as impressions')
            ->groupBy('date')->orderBy('date')->get();

        return response()->json(['summary' => $stats, 'daily' => $daily, 'period' => ['from' => $from, 'to' => $to]]);
    }

    public function campaign(int $id): JsonResponse {
        $campaign = MetaCampaign::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $from     = request('from', now()->subDays(30)->toDateString());
        $to       = request('to',   now()->toDateString());

        $insights = MetaInsight::where('object_type', 'campaign')->where('object_id', $id)
            ->whereBetween('date', [$from, $to])->orderBy('date')->get();

        return response()->json(['campaign' => $campaign, 'insights' => $insights]);
    }

    public function sync(int $campaignId): JsonResponse {
        $campaign = MetaCampaign::where('id',$campaignId)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $from     = request('from', now()->subDays(7)->toDateString());
        $to       = request('to',   now()->toDateString());
        $this->svc->syncInsights($campaign, $from, $to);
        return response()->json(['message' => 'Insights synced.']);
    }
}
