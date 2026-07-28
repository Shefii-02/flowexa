<?php

namespace App\Modules\Otp\Services;

use App\Models\Company;
use App\Modules\Otp\DTOs\OtpResultDTO;
use App\Modules\Otp\DTOs\SendOtpDTO;
use App\Modules\Otp\DTOs\VerifyOtpDTO;
use App\Modules\Otp\DTOs\VerifyResultDTO;
use App\Modules\Otp\Exceptions\OtpException;
use App\Modules\Otp\Repositories\OtpRepository;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OtpService
{
    public function __construct(
        private readonly OtpRepository $otpRepository,
        private readonly WalletService $walletService,
    ) {}

    public function send(Company $company, SendOtpDTO $dto): OtpResultDTO
    {
        $this->walletService->debit(
            companyId:   $company->id,
            amount:      1,
            description: "OTP send to {$dto->phone}",
            refId:       $dto->phone,
            refType:     'otp',
        );

        $this->otpRepository->invalidatePrevious($dto->phone, $company->id);
        $otp    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $record = $this->otpRepository->create($company->id, $dto, $otp);
        $this->sendWhatsApp($company, $dto->phone, $otp);

        return new OtpResultDTO(refId: $record->ref_id, expiresInSeconds: 900);
    }

    public function verify(Company $company, VerifyOtpDTO $dto): VerifyResultDTO
    {
        $record = $this->otpRepository->findByRef($dto->refId, $company->id);

        if (!$record)                                throw OtpException::notFound();
        if ($record->isExpired())                    throw OtpException::expired();
        if ($record->device_id !== $dto->deviceId)   throw OtpException::deviceMismatch();
        if (!Hash::check($dto->otp, $record->otp))   throw OtpException::invalidOtp();

        $this->otpRepository->markUsed($record);
        return new VerifyResultDTO(verified: true, phone: $record->phone);
    }

    private function sendWhatsApp(Company $company, string $phone, string $otp): void
    {
        if (!$company->wa_phone_id || !$company->wa_access_token) {
            throw OtpException::waCredentialsMissing();
        }
        try {
            $response = Http::withToken(decrypt($company->wa_access_token))
                ->timeout(10)
                ->post("https://graph.facebook.com/v21.0/{$company->wa_phone_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phone,
                    'type'              => 'template',
                    'template'          => [
                        'name'       => $company->settings['otp_template'] ?? 'auth',
                        'language'   => ['code' => $company->settings['otp_language'] ?? 'en'],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]],
                            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $otp]]],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                throw OtpException::sendFailed($response->json('error.message') ?? 'Unknown error');
            }
        } catch (OtpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw OtpException::sendFailed($e->getMessage());
        }
    }
}
