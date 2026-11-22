<?php

namespace App\Http\Middleware;

use App\Modules\Analytics\Http\Controllers\TeamMembersController;
use Closure;
use Illuminate\Http\Request;

/**
 * The API side of the "staff vs team head" screens: `advanced:leads.manage` lets a request through only for someone who holds one of the
 * listed manage permissions, or is a team head (owner, admin, team lead, or anyone who can see the team). Plain staff get a 403 even if
 * they call the endpoint directly, so hiding the screen in the app is never the only protection.
 */
class AdvancedAccess
{
    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        $u = auth()->user();
        if (! $u) { return $next($request); }
        $u->loadMissing('role');
        if ($u->isSuperAdmin() || $u->hasAnyPermission($permissions) || TeamMembersController::canSeeTeam($u)) { return $next($request); }

        return response()->json(['message' => 'This is for team heads and managers.', 'error_code' => 'advanced_access_required'], 403);
    }
}
