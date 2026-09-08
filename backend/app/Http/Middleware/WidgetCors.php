<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permissive CORS for the public website-chat-widget endpoints — they are called from arbitrary
 * customer domains by design. Per-widget origin allow-listing is enforced in the controller against
 * the widget's `allowed_origins`, not here.
 */
class WidgetCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '*');

        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204, $this->headers($origin));
        }

        $response = $next($request);
        foreach ($this->headers($origin) as $k => $v) {
            $response->headers->set($k, $v);
        }
        return $response;
    }

    private function headers(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin'  => $origin === '' ? '*' : $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
            'Access-Control-Max-Age'       => '86400',
            'Vary'                         => 'Origin',
        ];
    }
}
