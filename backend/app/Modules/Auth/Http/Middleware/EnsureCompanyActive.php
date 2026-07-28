<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

// ─── Ensure Company is Active Middleware ──────────────────────────────────────
class EnsureCompanyActive
{
    // Routes that consume wallet balance
    private const WALLET_ROUTES = [
        'api.messages.*',
        'api.otp.send',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Superadmin has no company restriction
        if (!$user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $company = $user->company;

        if (!$company) {
            return response()->json(['message' => 'No company associated with this account.'], 403);
        }

        if ($company->status === 'suspended') {
            return response()->json([
                'message' => 'Your company account is suspended. Please contact support.',
                'status'  => 'suspended',
            ], 403);
        }

        // Check wallet on message-consuming routes
        if ($this->isWalletRoute($request)) {
            $wallet = $company->wallet;
            if (!$wallet || $wallet->balance < 1) {
                return response()->json([
                    'message' => 'Insufficient wallet balance. Please recharge to continue.',
                    'balance' => $wallet?->balance ?? 0,
                    'action'  => 'recharge',
                ], 402);
            }
        }

        return $next($request);
    }

    private function isWalletRoute(Request $request): bool
    {
        foreach (self::WALLET_ROUTES as $pattern) {
            if ($request->routeIs($pattern)) return true;
        }
        return false;
    }
}
