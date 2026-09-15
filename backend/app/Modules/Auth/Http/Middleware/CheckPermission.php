<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

// ─── Permission Middleware ────────────────────────────────────────────────────
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Superadmin and Owner bypass all checks — a real code-level guarantee rather
        // than relying on the `owner` role's seeded permissions row staying complete
        // (a permissions sync/reset could otherwise silently strip owner's access).
        if ($user->isSuperAdmin() || $user->isOwner()) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message'             => 'You do not have permission to perform this action.',
            'required_permission' => $permissions,
        ], 403);
    }
}
