<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

class MetaAdSetController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function audienceTemplates(): JsonResponse {
        return response()->json(['templates' => $this->svc->getAudienceTemplates()]);
    }

    public function index(int $campaignId): JsonResponse {
        $adSets = MetaAdSet::with(['audienceTemplate','ads'])
            ->where('meta_campaign_id', $campaignId)->where('company_id', auth()->user()->company_id)->get();
        return response()->json(['ad_sets' => $adSets]);
    }

    public function store(Request $request, int $campaignId): JsonResponse {
        $d = $request->validate([
            'name'                  => ['required','string','max:150'],
            'optimization_goal'     => ['required','string'],
            'billing_event'         => ['required','string'],
            'bid_strategy'          => ['nullable','string'],
            'daily_budget'          => ['nullable','numeric','min:10'],
            'lifetime_budget'       => ['nullable','numeric'],
            'targeting'             => ['required','array'],
            'audience_template_id'  => ['nullable','integer','exists:meta_audience_templates,id'],
            'start_time'            => ['nullable','date'],
            'end_time'              => ['nullable','date'],
            'placements'            => ['nullable','array'],
        ]);
        $campaign = MetaCampaign::where('id',$campaignId)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $adSet    = $this->svc->createAdSet($campaign, $d);
        return response()->json(['message' => 'Ad set created.', 'ad_set' => $adSet], 201);
    }

    public function update(Request $request, int $id): JsonResponse {
        $adSet = MetaAdSet::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $adSet->update($request->only(['name','daily_budget','lifetime_budget','start_time','end_time']));
        return response()->json(['ad_set' => $adSet->fresh()]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse {
        $request->validate(['status' => ['required','in:ACTIVE,PAUSED,ARCHIVED']]);
        $adSet = MetaAdSet::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $adSet->update(['status' => $request->status]);
        return response()->json(['message' => "Ad set {$request->status}."]);
    }

    public function destroy(int $id): JsonResponse {
        MetaAdSet::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail()->delete();
        return response()->json(['message' => 'Ad set deleted.']);
    }
}
