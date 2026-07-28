<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

// ── Campaigns ─────────────────────────────────────────────────────────────
class MetaCampaignController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function index(): JsonResponse {
        $campaigns = MetaCampaign::with(['adAccount','adSets'])
            ->where('company_id', auth()->user()->company_id)->latest()->paginate(20);
        return response()->json($campaigns);
    }

    public function show(int $id): JsonResponse {
        $c = MetaCampaign::with(['adSets.ads'])->where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        return response()->json(['campaign' => $c]);
    }

    public function store(Request $request): JsonResponse {
        $d = $request->validate([
            'account_id'           => ['required','integer','exists:meta_ad_accounts,id'],
            'name'                 => ['required','string','max:150'],
            'objective'            => ['required','in:LEAD_GENERATION,LINK_CLICKS,CONVERSIONS,APP_INSTALLS,BRAND_AWARENESS,REACH,VIDEO_VIEWS,MESSAGES,STORE_VISITS'],
            'buying_type'          => ['nullable','in:AUCTION,RESERVED'],
            'special_ad_category'  => ['nullable','boolean'],
            'spend_cap'            => ['nullable','numeric','min:10'],
        ]);
        $account  = MetaAdAccount::where('id', $d['account_id'])->where('company_id', auth()->user()->company_id)->firstOrFail();
        $campaign = $this->svc->createCampaign($account, $d);
        return response()->json(['message' => 'Campaign created.', 'campaign' => $campaign], 201);
    }

    public function update(Request $request, int $id): JsonResponse {
        $campaign = MetaCampaign::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $campaign->update($request->only(['name','spend_cap']));
        return response()->json(['campaign' => $campaign->fresh()]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse {
        $request->validate(['status' => ['required','in:ACTIVE,PAUSED,ARCHIVED,DELETED']]);
        $campaign = MetaCampaign::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $this->svc->updateCampaignStatus($campaign, $request->status);
        return response()->json(['message' => "Campaign {$request->status}."]);
    }

    public function destroy(int $id): JsonResponse {
        $campaign = MetaCampaign::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        if ($campaign->meta_campaign_id) $this->svc->updateCampaignStatus($campaign, 'DELETED');
        $campaign->delete();
        return response()->json(['message' => 'Campaign deleted.']);
    }
}
