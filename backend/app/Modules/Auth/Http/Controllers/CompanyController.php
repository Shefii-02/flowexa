<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\DTOs\UpdateCompanyDTO;
use App\Modules\Auth\DTOs\WaCredentialsDTO;
use App\Modules\Auth\Http\Requests\UpdateCompanyRequest;
use App\Modules\Auth\Http\Requests\WaCredentialsRequest;
use App\Modules\Auth\Http\Resources\CompanyResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    // ─── GET /company ─────────────────────────────────────────────────────────
    public function show(): JsonResponse
    {
        $company = auth()->user()->company->load(['plan','wallet']);

        return response()->json([
            'company' => new CompanyResource($company),
        ]);
    }

    // ─── PUT /company ─────────────────────────────────────────────────────────
    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $company = $this->authService->updateCompany(
            auth()->user()->company,
            UpdateCompanyDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Company profile updated.',
            'company' => new CompanyResource($company),
        ]);
    }

    // ─── POST /company/wa-credentials ────────────────────────────────────────
    public function updateWaCredentials(WaCredentialsRequest $request): JsonResponse
    {
        $company = $this->authService->updateWaCredentials(
            auth()->user()->company,
            WaCredentialsDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message'    => 'WhatsApp credentials updated.',
            'wa_phone_id'=> $company->wa_phone_id,
            'connected'  => true,
        ]);
    }

    // ─── POST /company/regenerate-token ───────────────────────────────────────
    public function regenerateToken(): JsonResponse
    {
        $rawToken = $this->authService->regenerateToken(auth()->user()->company);

        return response()->json([
            'message'       => 'Private token regenerated. Store this safely — it will not be shown again.',
            'private_token' => $rawToken,
        ]);
    }
}
