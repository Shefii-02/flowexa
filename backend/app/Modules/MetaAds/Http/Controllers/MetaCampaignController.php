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
        $allowed = auth()->user()->allowedAccountIds('meta_ads_account');

        $campaigns = MetaCampaign::with(['adAccount','adSets'])
            ->where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('meta_ad_account_id', $allowed))
            ->latest()->paginate(20);
        return response()->json($campaigns);
    }

    public function show(int $id): JsonResponse {
        $allowed = auth()->user()->allowedAccountIds('meta_ads_account');

        $c = MetaCampaign::with(['adAccount','adSets.ads'])->where('id',$id)->where('company_id',auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('meta_ad_account_id', $allowed))
            ->firstOrFail();
        return response()->json(['campaign' => $c]);
    }

    public function store(Request $request): JsonResponse {
        $d = $request->validate([
            'account_id'           => ['required','integer','exists:meta_ad_accounts,id'],
            'name'                 => ['required','string','max:150'],
            'objective'            => ['required','in:LEAD_GENERATION,LINK_CLICKS,CONVERSIONS,APP_INSTALLS,BRAND_AWARENESS,REACH,VIDEO_VIEWS,MESSAGES,STORE_VISITS'],
            'buying_type'          => ['nullable','in:AUCTION,RESERVED'],
            // Legacy boolean kept for back-compat; `special_ad_categories` is the real Meta field.
            'special_ad_category'  => ['nullable','boolean'],
            'special_ad_categories'   => ['nullable','array'],
            'special_ad_categories.*' => ['string','in:HOUSING,EMPLOYMENT,CREDIT,ISSUES_ELECTIONS_POLITICS,ONLINE_GAMBLING_AND_GAMING'],
            'special_ad_category_country'   => ['nullable','array'],
            'special_ad_category_country.*' => ['string','size:2'],
            'spend_cap'            => ['nullable','numeric','min:10'],
        ]);
        $account  = MetaAdAccount::where('id', $d['account_id'])->where('company_id', auth()->user()->company_id)->firstOrFail();
        $campaign = $this->svc->createCampaign($account, $d);
        return response()->json(['message' => 'Campaign created.', 'campaign' => $campaign], 201);
    }

    public function update(Request $request, int $id): JsonResponse {
        $d = $request->validate([
            'name'                    => ['sometimes','string','max:150'],
            'spend_cap'               => ['sometimes','nullable','numeric','min:0'],
            'special_ad_categories'   => ['sometimes','array'],
            'special_ad_categories.*' => ['string','in:HOUSING,EMPLOYMENT,CREDIT,ISSUES_ELECTIONS_POLITICS,ONLINE_GAMBLING_AND_GAMING'],
        ]);
        $campaign = MetaCampaign::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $campaign = $this->svc->updateCampaign($campaign, $d);
        return response()->json(['message' => 'Campaign updated.', 'campaign' => $campaign]);
    }

    public function duplicate(Request $request, int $id): JsonResponse {
        $campaign = MetaCampaign::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $copy = $this->svc->duplicateCampaign($campaign, $request->boolean('deep', true));
        return response()->json(['message' => 'Campaign duplicated (paused).', 'campaign' => $copy], 201);
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
