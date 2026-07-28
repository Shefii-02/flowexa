<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

class MetaCreativeController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function index(Request $request): JsonResponse {
        $creatives = MetaAdCreative::where('company_id', auth()->user()->company_id)
            ->when($request->format, fn($q) => $q->where('format', $request->format))
            ->latest()->get();
        return response()->json(['creatives' => $creatives]);
    }

    public function show(int $id): JsonResponse {
        $c = MetaAdCreative::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        return response()->json(['creative' => $c->load(['image','video'])]);
    }

    public function store(Request $request): JsonResponse {
        $d = $request->validate([
            'account_id'     => ['required','integer'],
            'format'         => ['required','in:image,video,carousel'],
            'name'           => ['nullable','string','max:150'],
            'primary_text'   => ['required','string','max:500'],
            'headline'       => ['nullable','string','max:255'],
            'description'    => ['nullable','string','max:255'],
            'call_to_action' => ['nullable','string'],
            'destination_url'=> ['nullable','url'],
            'image_id'       => ['required_if:format,image','nullable','integer'],
            'video_id'       => ['required_if:format,video','nullable','integer'],
            'carousel_cards' => ['required_if:format,carousel','nullable','array','min:2','max:10'],
            'page_id'        => ['nullable','string'],
        ]);
        $account  = MetaAdAccount::where('id', $d['account_id'])->where('company_id', auth()->user()->company_id)->firstOrFail();
        $creative = $this->svc->createCreative($account, $d);
        return response()->json(['message' => 'Creative created.', 'creative' => $creative], 201);
    }

    public function destroy(int $id): JsonResponse {
        MetaAdCreative::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail()->delete();
        return response()->json(['message' => 'Creative deleted.']);
    }
}
