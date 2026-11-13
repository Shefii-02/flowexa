<?php

namespace App\Http\Middleware;

use App\Support\Entitlements;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuses an API call to a module the company's plan (and add-ons) does not include. The module is read from the URL
 * prefix (see Modules::REGISTRY), so no route group needs editing and a new route under an owned prefix is covered at once.
 */
class ModuleGate
{
    public function handle(Request $request, Closure $next): mixed
    {
        $module = Modules::forPath($request->path());
        $user = $module ? auth()->user() : null;
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) { return $next($request); }

        if (!Entitlements::has($user->company, $module)) {
            $m = Modules::get($module);
            return response()->json([
                'message'    => "{$m['label']} is not part of your plan. Upgrade your plan or buy the add-on to use it.",
                'error_code' => 'module_not_available',
                'module'     => $module,
                'label'      => $m['label'],
            ], 403);
        }
        return $next($request);
    }
}
