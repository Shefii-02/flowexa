<?php

namespace App\Modules\Settings\Services;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

// ─── Settings Service ─────────────────────────────────────────────────────────
class SettingsService
{
    public function getCompany(int $companyId): Company
    {
        return Company::with(['plan','wallet'])->findOrFail($companyId);
    }

    public function update(Company $company, UpdateSettingsDTO $dto): Company
    {
        $data = array_filter([
            'name'    => $dto->name,
            'email'   => $dto->email,
            'phone'   => $dto->phone,
            'website' => $dto->website,
        ], fn($v) => !is_null($v));

        if ($dto->settings) {
            $data['settings'] = array_merge($company->settings ?? [], $dto->settings);
        }

        $company->update($data);
        return $company->fresh(['plan','wallet']);
    }

    public function updateWaCredentials(Company $company, WaCredentialsDTO $dto): Company
    {
        $company->update([
            'wa_phone_id'     => $dto->waPhoneId,
            'wa_access_token' =>
            encrypt($dto->waAccessToken),
            'wa_business_id'  => $dto->waBusinessId,
        ]);
        return $company->fresh();
    }

    public function regenerateToken(Company $company): string
    {
        $raw = Str::random(40);
        $company->update(['private_token' => encrypt($raw)]);
        return $raw;
    }

    public function uploadLogo(Company $company, \Illuminate\Http\UploadedFile $file): string
    {
        // Delete old logo
        if ($company->logo) Storage::disk('public')->delete($company->logo);

        $path = $file->store("logos/{$company->id}", 'public');
        $company->update(['logo' => $path]);
        return Storage::url($path);
    }

    public function getOtpCredentials(Company $company): array
    {
        return [
            'otp_api_key' => $company->app_id,
            'otp_secret'  => decrypt($company->private_token),
        ];
    }
}


