<?php

namespace App\Modules\WaChat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Blocks WA Chat (open-wa) routes once Company.wa_chat_token_expires_at has passed,
 * with a clear message — instead of letting the request through to fail downstream
 * with the gateway's opaque "Invalid API key" the moment it actually calls out.
 */
class EnsureWaChatTokenValid
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Superadmin isn't scoped to a company's WA Chat connection.
        if (!$user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $company = $user->company;

        if ($company && $company->wa_chat_token_expired) {
            return response()->json([
                'message' => 'Your WA Chat connection has expired. Ask your administrator to renew it from the superadmin panel.',
                'wa_chat_token_status' => 'expired',
                'wa_chat_token_expires_at' => $company->wa_chat_token_expires_at?->toIso8601String(),
            ], 403);
        }

        return $next($request);
    }
}
