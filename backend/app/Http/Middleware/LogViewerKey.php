<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Allows the log viewer only with ?key= / X-Log-Key equal to LOG_VIEWER_KEY (404 when unset or wrong). */
class LogViewerKey
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('app.log_viewer_key');
        $given    = (string) ($request->header('X-Log-Key') ?: $request->query('key'));
        abort_if($expected === '' || !hash_equals($expected, $given), 404);
        return $next($request);
    }
}
