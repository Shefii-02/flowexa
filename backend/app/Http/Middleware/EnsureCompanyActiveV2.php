<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * EnsureCompanyActiveV2
 *
 * Replaces original EnsureCompanyActive.
 * - Suspended / expired → login allowed, but ALL data API routes return 402/403
 * - A special header X-Company-Status is always set so frontend can show the locked dashboard
 */
class EnsureCompanyActiveV2
{
    // Routes that are ALWAYS allowed even when suspended/expired (login, refresh, auth/me)
    private const ALWAYS_ALLOWED = [
        'api/v1/auth/login',
        'api/v1/auth/refresh',
        'api/v1/auth/logout',
        'api/v1/auth/me',
        'api/v1/wallet/create-order',
        'api/v1/wallet/verify-payment',
        'api/v1/wallet/packages',
        'api/v1/plan-purchase',
        'api/v1/razorpay/webhook',
        'api/v1/webhook/whatsapp',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = auth()->user();
        if (!$user || $user->isSuperAdmin()) return $next($request);

        $company = $user->company;
        if (!$company) {
            return response()->json(['message' => 'No company associated.'], 403);
        }

        // Always allow whitelisted routes
        $path = $request->path();
        foreach (self::ALWAYS_ALLOWED as $allowed) {
            if (str_starts_with($path, $allowed)) return $next($request);
        }

        $status = $this->getCompanyStatus($company);

        // Set status header so frontend knows
        $response = $next($request);
        $response->headers->set('X-Company-Status', $status);

        if (in_array($status, ['suspended', 'expired', 'cancelled'])) {
            $messages = [
                'suspended' => 'Your account is suspended. Please contact support.',
                'expired'   => 'Your plan has expired. Please renew to continue.',
                'cancelled' => 'Your subscription has been cancelled.',
            ];

            return response()->json([
                'message'        => $messages[$status],
                'error_code'     => "account_{$status}",
                'company_status' => $status,
                'action'         => $status === 'suspended' ? 'contact_support' : 'renew_plan',
            ], 402)->withHeaders(['X-Company-Status' => $status]);
        }

        return $response;
    }

    private function getCompanyStatus(\App\Models\Company $company): string
    {
        if ($company->status === 'suspended') return 'suspended';

        // Check plan expiry
        if ($company->plan_expires_at && $company->plan_expires_at->isPast()) {
            // Auto-update status
            $company->updateQuietly(['status' => 'expired']);
            return 'expired';
        }

        if ($company->status === 'expired')   return 'expired';
        if ($company->status === 'cancelled') return 'cancelled';
        if ($company->status === 'trial') {
            if ($company->trial_ends_at && $company->trial_ends_at->isPast()) {
                $company->updateQuietly(['status' => 'expired']);
                return 'expired';
            }
        }

        return 'active';
    }
}
