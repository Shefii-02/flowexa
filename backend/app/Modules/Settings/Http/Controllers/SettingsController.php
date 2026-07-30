<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Plan;
use App\Modules\Auth\Http\Resources\CompanyResource;
use App\Modules\Settings\DTOs\MessageLogFilterDTO;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use App\Modules\Settings\Http\Requests\SuperAdminCreateCompanyRequest;
use App\Modules\Settings\Http\Requests\TopUpRequest;
use App\Modules\Settings\Http\Requests\UpdateCompanyStatusRequest;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Http\Requests\WaCredentialsRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SuperAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// ─── Settings Controller ──────────────────────────────────────────────────────
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settingsService) {}

    public function index(): JsonResponse
    {
        $company = $this->settingsService->getCompany(auth()->user()->company_id);
        return response()->json(['company' => CompanyResource::new($company)]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $company = $this->settingsService->update(
            auth()->user()->company,
            UpdateSettingsDTO::fromRequest($request->validated())
        );
        return response()->json(['message' => 'Settings updated.', 'company' => $company]);
    }

    public function updateWaCredentials(WaCredentialsRequest $request): JsonResponse
    {
        $this->settingsService->updateWaCredentials(
            auth()->user()->company,
            WaCredentialsDTO::fromRequest($request->validated())
        );
        return response()->json(['message' => 'WhatsApp credentials updated.', 'connected' => true]);
    }

    public function regenerateToken(): JsonResponse
    {
        $token = $this->settingsService->regenerateToken(auth()->user()->company);
        return response()->json([
            'message'       => 'Token regenerated. Store it safely — shown only once.',
            'private_token' => $token,
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $url = $this->settingsService->uploadLogo(auth()->user()->company, $request->file('logo'));
        return response()->json(['message' => 'Logo uploaded.', 'logo_url' => $url]);
    }

    public function getOtpCredentials(): JsonResponse
    {
        $credentials = $this->settingsService->getOtpCredentials(auth()->user()->company);

        return response()->json(['otp_credentials' => $credentials]);
    }
}
