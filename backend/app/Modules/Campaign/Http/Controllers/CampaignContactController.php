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


class CampaignContactController extends Controller
{
    public function __construct(private readonly CampaignService $campaignService) {}

    public function index(CampaignContactFilterRequest $request, int $campaign): JsonResponse
    {
        $paginator = $this->campaignService->contacts($campaign, auth()->user()->company_id, CampaignContactFilterDTO::fromRequest($request->validated()));
        return response()->json([
            'data'         => CampaignContactResource::collection($paginator->items()),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ]);
    }
}
