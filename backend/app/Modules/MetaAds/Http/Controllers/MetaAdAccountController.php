<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\{MetaAdsConnectService, MetaAdsService};
use Illuminate\Support\Facades\Cache;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;

// ── Ad Accounts ───────────────────────────────────────────────────────────
class MetaAdAccountController extends Controller
{
    public function __construct(private MetaAdsService $svc, private MetaAdsConnectService $connect) {}

    public function index(): JsonResponse {
        $allowed = auth()->user()->allowedAccountIds('meta_ads_account');
        return response()->json(['accounts' => MetaAdAccount::where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->get()]);
    }

    public function store(Request $request): JsonResponse {
        $d = $request->validate([
            'ad_account_id' => ['required','string','regex:/^act_[0-9]+$/'],
            'access_token'  => ['required','string'],
            'page_id'       => ['nullable','string'],
            'page_name'     => ['nullable','string'],
            'business_id'   => ['nullable','string','max:50'],                 // Advanced: Business Manager id
            'token_expires_at' => ['nullable','date','after:now'],            // Advanced: when the pasted token expires, if known
        ]);

        // Inline (not plan.limit route middleware): connectAdAccount is an updateOrCreate keyed on
        // ad_account_id — a blunt count-vs-max check on the route would wrongly block
        // reconnecting/refreshing the token on an account this company already has.
        $companyId = auth()->user()->company_id;
        $isNewAccount = !MetaAdAccount::where('company_id', $companyId)->where('ad_account_id', $d['ad_account_id'])->exists();
        if ($isNewAccount) {
            $limit = auth()->user()->company?->plan?->max_meta_ads_accounts;
            if ($limit !== null) {
                $count = MetaAdAccount::where('company_id', $companyId)->count();
                if ($count >= $limit) {
                    return response()->json([
                        'message'    => "Your plan allows {$limit} Meta Ads account(s). Upgrade your plan to connect more.",
                        'error_code' => 'plan_limit_reached',
                        'resource'   => 'meta_ads_accounts',
                    ], 403);
                }
            }
        }

        // a pasted token: find out when it expires (when Meta can tell us) unless the user already said
        if (empty($d['token_expires_at'])) {
            $d['token_expires_at'] = $this->connect->tokenInfo($d['access_token'])['expires_at'];
        }
        $account = $this->svc->connectAdAccount($companyId, $d);
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

    // ── "Continue with Facebook" / "find my accounts from a token" ─────────────────────────────────────

    private function connectKey(string $handle): string { return 'meta-ads-connect:' . auth()->id() . ':' . $handle; }

    /**
     * POST /meta-ads/accounts/discover { user_access_token } — what can this token connect?
     * The (long-lived) token is kept on the server for 15 minutes; the browser gets only a handle for the connect step.
     */
    public function discover(Request $request): JsonResponse
    {
        $token = trim($request->validate(['user_access_token' => ['required', 'string', 'max:2000']])['user_access_token']);
        try {
            $r = $this->connect->discover($token, (int) auth()->user()->company_id);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Meta did not accept that token: ' . $e->getMessage()], 422);
        }
        $handle = bin2hex(random_bytes(16));
        Cache::put($this->connectKey($handle), ['token' => $r['token'], 'expires_at' => $r['expires_at']?->toIso8601String(), 'accounts' => $r['accounts'], 'pages' => $r['pages']], now()->addMinutes(15));

        return response()->json([
            'handle' => $handle, 'long_lived' => $r['long_lived'], 'expires_at' => $r['expires_at']?->toIso8601String(), 'never_expires' => $r['never_expires'],
            'scopes' => $r['scopes'], 'missing_permissions' => $r['missing'], 'ad_accounts' => $r['accounts'], 'pages' => $r['pages'],
        ]);
    }

    /** POST /meta-ads/accounts/connect-selected { handle, ad_account_ids[], page_id? } — connect the ad accounts the person ticked. Also refreshes the token of one already connected. */
    public function connectSelected(Request $request): JsonResponse
    {
        $d = $request->validate(['handle' => ['required', 'string', 'size:32'], 'ad_account_ids' => ['required', 'array', 'min:1', 'max:20'], 'ad_account_ids.*' => ['string', 'regex:/^act_[0-9]+$/'], 'page_id' => ['nullable', 'string', 'max:50']]);
        $found = Cache::get($this->connectKey($d['handle']));
        if (!$found) {
            return response()->json(['message' => 'That connection expired. Continue with Facebook again.', 'error_code' => 'connect_expired'], 410);
        }

        $companyId = (int) auth()->user()->company_id;
        $limit = auth()->user()->company?->plan?->max_meta_ads_accounts;
        $pageId = $d['page_id'] ?? null;                                  // optional: an ad account can be connected without a Page
        $page = $pageId ? collect($found['pages'])->firstWhere('id', $pageId) : null;
        abort_if($pageId && !$page, 422, 'That Page is not one this login can use.');
        $byId = collect($found['accounts'])->keyBy('ad_account_id');

        $connected = []; $skipped = [];
        foreach (array_unique($d['ad_account_ids']) as $id) {
            if (!isset($byId[$id])) { $skipped[] = ['ad_account_id' => $id, 'reason' => 'This login cannot manage that ad account.']; continue; }
            $isNew = !MetaAdAccount::where('company_id', $companyId)->where('ad_account_id', $id)->exists();
            if ($isNew && $limit !== null && MetaAdAccount::where('company_id', $companyId)->count() >= $limit) {
                $skipped[] = ['ad_account_id' => $id, 'reason' => "Your plan allows {$limit} Meta Ads account(s)."]; continue;
            }
            try {
                $connected[] = $this->svc->connectAdAccount($companyId, [
                    'ad_account_id' => $id, 'access_token' => $found['token'], 'page_id' => $page['id'] ?? null, 'page_name' => $page['name'] ?? null,
                    'business_id' => $byId[$id]['business_id'], 'token_expires_at' => $found['expires_at'] ? Carbon::parse($found['expires_at']) : null,
                ]);
            } catch (\Throwable $e) {
                $skipped[] = ['ad_account_id' => $id, 'reason' => $e->getMessage()];
            }
        }
        if ($connected) { Cache::forget($this->connectKey($d['handle'])); }      // done — the token stays only in the encrypted account row

        $n = count($connected);
        return response()->json([
            'connected' => $connected, 'skipped' => $skipped,
            'message' => $n ? ($n === 1 ? "Ad account {$connected[0]->ad_account_name} connected." : "{$n} ad accounts connected.") . ($skipped ? ' ' . count($skipped) . ' skipped.' : '') : 'Nothing was connected.',
        ], $n ? 201 : 422);
    }
}
