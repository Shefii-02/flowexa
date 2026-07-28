<?php

namespace App\Modules\Otp\Http\Middleware;

use App\Models\Company;
use App\Modules\Otp\Exceptions\OtpException;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates external API calls using X-App-Id + X-Private-Token headers.
 * Binds the resolved Company to the request so controllers can use it.
 */
class OtpApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $appId       = $request->header('X-App-Id');
        $privateToken= $request->header('X-Private-Token');

        if (!$appId || !$privateToken) {
            throw OtpException::unauthorized();
        }

        $company = Company::where('app_id', $appId)
            ->where('status', 'active')
            ->first();

        if (!$company) {
            throw OtpException::unauthorized();
        }

        // Decrypt stored token and compare
        try {
            $storedToken = decrypt($company->private_token);
        } catch (\Exception) {
            throw OtpException::unauthorized();
        }

        if (!hash_equals($storedToken, $privateToken)) {
            throw OtpException::unauthorized();
        }

        // Bind company to request for use in controller
        $request->merge(['_otp_company' => $company]);

        return $next($request);
    }
}
