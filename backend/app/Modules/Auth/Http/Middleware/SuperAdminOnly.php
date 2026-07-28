<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;


// ─── SuperAdmin Only Middleware ───────────────────────────────────────────────
class SuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->user()?->isSuperAdmin()) {
            return response()->json(['message' => 'Super admin access required.'], 403);
        }

        return $next($request);
    }
}
