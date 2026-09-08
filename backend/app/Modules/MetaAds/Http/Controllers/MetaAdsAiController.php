<?php

namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MetaAdAccount;
use App\Modules\MetaAds\Services\MetaCampaignPlanner;
use Illuminate\Http\{JsonResponse, Request};

/**
 * AI campaign builder: brief in → editable draft out → (separately) build it on Meta.
 * Uses the company's own configured LLM key.
 */
class MetaAdsAiController extends Controller
{
    public function __construct(private readonly MetaCampaignPlanner $planner) {}

    public function status(): JsonResponse
    {
        return response()->json(['configured' => $this->planner->isConfigured(auth()->user()->company)]);
    }

    public function plan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id'           => ['required', 'integer', 'exists:meta_ad_accounts,id'],
            'goal'                 => ['required', 'string', 'max:500'],
            'business_name'        => ['nullable', 'string', 'max:150'],
            'business_description' => ['nullable', 'string', 'max:1000'],
            'website'              => ['nullable', 'url'],
            'audience_description' => ['nullable', 'string', 'max:1000'],
            'locations'            => ['nullable', 'string', 'max:300'],
            'daily_budget'         => ['nullable', 'numeric', 'min:1'],
            'language'             => ['nullable', 'string', 'max:40'],
        ]);

        $account = MetaAdAccount::where('id', $data['account_id'])
            ->where('company_id', auth()->user()->company_id)->firstOrFail();

        try {
            $draft = $this->planner->plan(auth()->user()->company, $account, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['draft' => $draft]);
    }

    public function build(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:meta_ad_accounts,id'],
            'draft'      => ['required', 'array'],
            'draft.campaign'  => ['required', 'array'],
            'draft.ad_set'    => ['required', 'array'],
            'draft.audience'  => ['required', 'array'],
            'draft.creatives' => ['nullable', 'array'],
            'draft.media'     => ['nullable', 'array'],
        ]);

        $account = MetaAdAccount::where('id', $data['account_id'])
            ->where('company_id', auth()->user()->company_id)->firstOrFail();

        try {
            $result = $this->planner->build($account, $data['draft']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Campaign built (paused). Add media and review before going live.', ...$result], 201);
    }
}
