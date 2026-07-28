<?php

namespace App\Modules\Otp\Repositories;

use App\Models\OtpVerification;
use App\Modules\Otp\DTOs\SendOtpDTO;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OtpRepository
{
    public function invalidatePrevious(string $phone, int $companyId): void
    {
        OtpVerification::where('phone', $phone)
            ->where('company_id', $companyId)
            ->where('is_used', false)
            ->update(['is_used' => true]);
    }

    public function create(int $companyId, SendOtpDTO $dto, string $otp): OtpVerification
    {
        return OtpVerification::create([
            'company_id' => $companyId,
            'ref_id'     => (string) Str::uuid(),
            'phone'      => $dto->phone,
            'otp'        => Hash::make($otp),
            'device_id'  => $dto->deviceId,
            'is_used'    => false,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function findByRef(string $refId, int $companyId): ?OtpVerification
    {
        return OtpVerification::where('ref_id', $refId)
            ->where('company_id', $companyId)
            ->where('is_used', false)
            ->first();
    }

    public function markUsed(OtpVerification $otp): void
    {
        $otp->update(['is_used' => true]);
    }
}
