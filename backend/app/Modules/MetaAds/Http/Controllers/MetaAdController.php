<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

// ── Ads ───────────────────────────────────────────────────────────────────
class MetaAdController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function index(int $adSetId): JsonResponse {
        $ads = MetaAd::with(['creative'])->where('meta_ad_set_id', $adSetId)->where('company_id', auth()->user()->company_id)->get();
        return response()->json(['ads' => $ads]);
    }

    public function store(Request $request, int $adSetId): JsonResponse {
        $d = $request->validate([
            'creative_id' => ['required','integer','exists:meta_ad_creatives,id'],
            'name'        => ['required','string','max:150'],
            'publish'     => ['nullable','boolean'],
        ]);
        $adSet    = MetaAdSet::where('id',$adSetId)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $creative = MetaAdCreative::where('id',$d['creative_id'])->where('company_id',auth()->user()->company_id)->firstOrFail();
        $ad       = $this->svc->createAd($adSet, $creative, $d);
        return response()->json(['message' => $d['publish'] ? 'Ad published.' : 'Ad created as draft.', 'ad' => $ad], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse {
        $request->validate(['status' => ['required','in:ACTIVE,PAUSED,ARCHIVED']]);
        $ad = MetaAd::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $this->svc->updateAdStatus($ad, $request->status);
        return response()->json(['message' => "Ad {$request->status}."]);
    }

    public function syncReview(int $id): JsonResponse {
        $ad = MetaAd::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $ad = $this->svc->syncAdReviewStatus($ad);
        return response()->json(['ad' => $ad]);
    }

    public function destroy(int $id): JsonResponse {
        $ad = MetaAd::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $this->svc->updateAdStatus($ad, 'DELETED');
        $ad->delete();
        return response()->json(['message' => 'Ad deleted.']);
    }
}
