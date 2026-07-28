<?php
namespace App\Modules\Otp\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Modules\Otp\DTOs\SendOtpDTO;
use App\Modules\Otp\DTOs\VerifyOtpDTO;
use App\Modules\Otp\Http\Requests\SendOtpRequest;
use App\Modules\Otp\Http\Requests\VerifyOtpRequest;
use App\Modules\Otp\Services\OtpService;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otpService) {}

    public function send(SendOtpRequest $request): JsonResponse
    {
        $result = $this->otpService->send($request->get('_otp_company'), SendOtpDTO::fromRequest($request->validated()));
        return response()->json(['message' => 'OTP sent.', 'ref_id' => $result->refId, 'expires_in_seconds' => $result->expiresInSeconds]);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->otpService->verify($request->get('_otp_company'), VerifyOtpDTO::fromRequest($request->validated()));
        return response()->json(['verified' => $result->verified, 'phone' => $result->phone, 'message' => 'OTP verified.']);
    }
}
