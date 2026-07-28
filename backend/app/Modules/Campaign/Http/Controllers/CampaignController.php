<?php

namespace App\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campaign\DTOs\CampaignContactFilterDTO;
use App\Modules\Campaign\DTOs\CampaignFilterDTO;
use App\Modules\Campaign\DTOs\CreateCampaignDTO;
use App\Modules\Campaign\DTOs\UpdateCampaignDTO;
use App\Modules\Campaign\Http\Requests\CampaignContactFilterRequest;
use App\Modules\Campaign\Http\Requests\CampaignFilterRequest;
use App\Modules\Campaign\Http\Requests\CreateCampaignRequest;
use App\Modules\Campaign\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaign\Http\Resources\CampaignCollection;
use App\Modules\Campaign\Http\Resources\CampaignContactResource;
use App\Modules\Campaign\Http\Resources\CampaignResource;
use App\Modules\Campaign\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaignService) {}

    public function index(CampaignFilterRequest $request): JsonResponse
    {
        $paginator = $this->campaignService->list(auth()->user()->company_id, CampaignFilterDTO::fromRequest($request->validated()));
        return (new CampaignCollection($paginator))->response();
    }

    public function show(int $campaign): JsonResponse
    {
        $c = $this->campaignService->show($campaign, auth()->user()->company_id);
        return response()->json(['campaign' => new CampaignResource($c)]);
    }

    public function stats(int $campaign): JsonResponse
    {
        $stats = $this->campaignService->stats($campaign, auth()->user()->company_id);
        return response()->json(['stats' => $stats]);
    }

    public function store(CreateCampaignRequest $request): JsonResponse
    {
        $csvPath = null;
        if ($request->hasFile('file')) {
            $csvPath = $request->file('file')->store('campaigns/' . auth()->user()->company_id, 'local');
        }

        $campaign = $this->campaignService->create(
            companyId: auth()->user()->company_id,
            userId:    auth()->id(),
            dto:       CreateCampaignDTO::fromRequest($request->validated(), $csvPath),
        );

        return response()->json(['message' => 'Campaign created as draft.', 'campaign' => new CampaignResource($campaign)], 201);
    }

    public function update(UpdateCampaignRequest $request, int $campaign): JsonResponse
    {
        $c = $this->campaignService->update($campaign, auth()->user()->company_id, UpdateCampaignDTO::fromRequest($request->validated()));
        return response()->json(['message' => 'Campaign updated.', 'campaign' => new CampaignResource($c)]);
    }

    public function destroy(int $campaign): JsonResponse
    {
        $this->campaignService->delete($campaign, auth()->user()->company_id);
        return response()->json(['message' => 'Campaign deleted.']);
    }

    public function launch(int $campaign): JsonResponse
    {
        $result = $this->campaignService->launch($campaign, auth()->user()->company_id);
        return response()->json([
            'message'          => "Campaign launched for {$result->totalContacts} contacts.",
            'total_contacts'   => $result->totalContacts,
            'wallet_debited'   => $result->walletDebited,
            'remaining_balance'=> $result->remainingBalance,
        ]);
    }

    public function pause(int $campaign): JsonResponse
    {
        $c = $this->campaignService->pause($campaign, auth()->user()->company_id);
        return response()->json(['message' => 'Campaign paused.', 'campaign' => new CampaignResource($c)]);
    }

    public function resume(int $campaign): JsonResponse
    {
        $c = $this->campaignService->resume($campaign, auth()->user()->company_id);
        return response()->json(['message' => 'Campaign resumed.', 'campaign' => new CampaignResource($c)]);
    }

    public function resendFailed(int $campaign): JsonResponse
    {
        $count = $this->campaignService->resendFailed($campaign, auth()->user()->company_id);
        return response()->json(['message' => "Queued {$count} failed messages for resend."]);
    }
}
