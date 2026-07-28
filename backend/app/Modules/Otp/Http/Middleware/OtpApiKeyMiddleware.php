<?php

// ════════════════════════════════════════════════════════════════════════════
// FILE: App\Modules\Otp\Http\Middleware\OtpApiKeyMiddleware.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Modules\Otp\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class OtpApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $appId       = $request->header('X-App-Id');
        $privateToken= $request->header('X-Private-Token');

        if (!$appId || !$privateToken) {
            return response()->json(['message' => 'X-App-Id and X-Private-Token headers are required.'], 401);
        }

        $company = Company::where('app_id', $appId)->where('status', 'active')->first();

        if (!$company) {
            return response()->json(['message' => 'Invalid App ID or company is inactive.'], 401);
        }

        try {
            $stored = decrypt($company->private_token);
        } catch (\Exception) {
            return response()->json(['message' => 'Token decryption failed.'], 500);
        }

        if (!hash_equals($stored, $privateToken)) {
            return response()->json(['message' => 'Invalid Private Token.'], 401);
        }

        // Check wallet
        $wallet = $company->wallet;
        if (!$wallet || $wallet->balance < 1) {
            return response()->json(['message' => 'Insufficient wallet balance.', 'balance' => $wallet?->balance ?? 0], 402);
        }

        // Attach company to request for controller use
        $request->merge(['_otp_company' => $company]);

        return $next($request);
    }
}
