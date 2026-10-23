<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

// ─── JWT Auth Middleware ──────────────────────────────────────────────────────
class JwtMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user || !$user->is_active) {
                return $this->error('Unauthorized.', 401);
            }

            // Per-staff channel switch (Staff → Access): the phone app sends X-Client: mobile and its
            // device-login tokens carry a `duid` claim; everything else is the web portal.
            $isMobile = $request->header('X-Client') === 'mobile' || JWTAuth::getPayload()->hasKey('duid');
            if (!$user->canUseChannel($isMobile ? 'mobile' : 'portal')) {
                return $this->error(
                    $isMobile ? 'Mobile app access is disabled for your account.' : 'Web portal access is disabled for your account.',
                    403,
                    $isMobile ? 'mobile_access_disabled' : 'portal_access_disabled',
                );
            }
        } catch (TokenExpiredException) {
            return $this->error('Token expired.', 401, 'token_expired');
        } catch (TokenInvalidException) {
            return $this->error('Token invalid.', 401, 'token_invalid');
        } catch (\Exception) {
            return $this->error('Token not found.', 401, 'token_missing');
        }

        return $next($request);
    }

    private function error(string $message, int $status, ?string $code = null)
    {
        return response()->json(array_filter([
            'message'    => $message,
            'error_code' => $code,
        ]), $status);
    }
}


