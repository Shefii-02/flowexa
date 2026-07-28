<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message'    => 'Unauthenticated.',
                'error_code' => 'unauthenticated',
            ], 401);
        }

        // if ($user->role?->name !== 'superadmin') {
        //     return response()->json([
        //         'message'    => 'Access denied. Only superadmin can perform this action.',
        //         'error_code' => 'forbidden',
        //     ], 403);
        // }

        return $next($request);
    }
}
