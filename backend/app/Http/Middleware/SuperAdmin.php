<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdmin
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

        $role = $user->role?->name;

        // if (!in_array($role, ['superadmin', 'superadmin_staff'])) {
        //     return response()->json([
        //         'message'    => 'Access denied. SuperAdmin only.',
        //         'error_code' => 'forbidden',
        //     ], 403);
        // }

        return $next($request);
    }
}
