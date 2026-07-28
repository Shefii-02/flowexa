<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

// ── Ad Accounts ───────────────────────────────────────────────────────────
class MetaAdAccountController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function index(): JsonResponse {
        return response()->json(['accounts' => MetaAdAccount::where('company_id', auth()->user()->company_id)->get()]);
    }

    public function store(Request $request): JsonResponse {
        $d = $request->validate([
            'ad_account_id' => ['required','string','regex:/^act_[0-9]+$/'],
            'access_token'  => ['required','string'],
            'page_id'       => ['nullable','string'],
            'page_name'     => ['nullable','string'],
        ]);
        $account = $this->svc->connectAdAccount(auth()->user()->company_id, $d);
        return response()->json(['message' => "Ad account {$account->ad_account_name} connected.", 'account' => $account], 201);
    }

    public function update(Request $request, int $id): JsonResponse {
        $account = MetaAdAccount::where('id',$id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $account->update($request->only(['ad_account_name','page_id','page_name']));
        if ($request->access_token) $account->update(['access_token' => $request->access_token]);
        return response()->json(['account' => $account->fresh()]);
    }

    public function destroy(int $id): JsonResponse {
        MetaAdAccount::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail()->delete();
        return response()->json(['message' => 'Ad account disconnected.']);
    }

    public function setDefault(int $id): JsonResponse {
        $cid = auth()->user()->company_id;
        MetaAdAccount::where('company_id',$cid)->update(['is_default' => false]);
        MetaAdAccount::where('id',$id)->where('company_id',$cid)->update(['is_default' => true]);
        return response()->json(['message' => 'Default account updated.']);
    }

    public function verify(int $id): JsonResponse {
        $account = MetaAdAccount::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        try {
            $info = \Illuminate\Support\Facades\Http::withToken($account->access_token)
                ->get("https://graph.facebook.com/v21.0/{$account->ad_account_id}", ['fields' => 'name,account_status'])
                ->json();
            $ok = isset($info['name']);
            $account->update(['last_synced_at' => now(), 'account_status' => $ok ? $account->account_status : 'error']);
            return response()->json(['verified' => $ok, 'name' => $info['name'] ?? null, 'status' => $info['account_status'] ?? null]);
        } catch (\Exception $e) {
            return response()->json(['verified' => false, 'error' => $e->getMessage()]);
        }
    }
}
