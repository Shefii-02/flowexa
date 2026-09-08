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
        $adSets = MetaAdSet::with(['audienceTemplate','audienceSet','ads'])
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
            // Three ways to set the audience: a compiled `targeting` spec, an `audience_set_id`
            // for one-click reuse, or an `audience` draft (our normalized shape) that the service
            // compiles — the "customize a copy" flow. At least one must be present.
            'targeting'             => ['required_without_all:audience_set_id,audience','array'],
            'audience_set_id'       => ['nullable','integer','exists:meta_audience_sets,id'],
            'audience'              => ['nullable','array'],
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
        $d = $request->validate([
            'name'            => ['sometimes','string','max:150'],
            'daily_budget'    => ['sometimes','nullable','numeric','min:10'],
            'lifetime_budget' => ['sometimes','nullable','numeric','min:10'],
            'bid_amount'      => ['sometimes','nullable','numeric','min:1'],
            'start_time'      => ['sometimes','nullable','date'],
            'end_time'        => ['sometimes','nullable','date'],
            'targeting'       => ['sometimes','array'],
            'audience_set_id' => ['sometimes','nullable','integer','exists:meta_audience_sets,id'],
            'audience'        => ['sometimes','array'],
        ]);
        $adSet = MetaAdSet::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $adSet = $this->svc->updateAdSet($adSet, $d);
        return response()->json(['message' => 'Ad set updated.', 'ad_set' => $adSet]);
    }

    public function duplicate(int $id): JsonResponse {
        $adSet = MetaAdSet::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $copy = $this->svc->duplicateAdSet($adSet);
        return response()->json(['message' => 'Ad set duplicated (paused).', 'ad_set' => $copy], 201);
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
