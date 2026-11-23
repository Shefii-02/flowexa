<?php

namespace App\Http\Middleware;

use App\Http\Controllers\MobileAppConfigController;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuses WEB-portal API calls into a menu section that was taken away from this user — by the superadmin (platform-wide), by their company
 * (staff / team-head layout) or by a per-staff choice. The phone app (X-Client: mobile) is never blocked here: web sections are a web-only
 * setting, and the app's own layout is applied by what it shows.
 *
 * HR is left out on purpose: attendance, leave, payslips and the home aggregate are self-service that the app and every staff member need,
 * and the HR admin endpoints already require hr.manage / team permissions.
 */
class ScreenGate
{
    /** First URL segment → web menu section id (the same ids as the sidebar sections). */
    private const SECTIONS = [
        'waha' => 'wa-chat', 'wa-chat-templates' => 'wa-chat', 'wa-export' => 'wa-chat', 'wa-chat' => 'wa-chat',
        'wa-cloud' => 'wa-cloud', 'survey-forms' => 'wa-cloud', 'phone-numbers' => 'wa-cloud',
        'wa-agent' => 'wa-agent', 'meta-ai' => 'wa-agent',
        'meta-ads' => 'meta-ads', 'instagram' => 'instagram', 'messenger' => 'messenger',
        'accounts' => 'accounts', 'crm' => 'crm',
        'leads' => 'leads', 'lead-categories' => 'leads', 'lead-assignments' => 'leads', 'lead-assignment-rules' => 'leads',
        'google' => 'integrations', 'email' => 'integrations',
        // self-contained pages — safe to close at the API. (Staff, Roles, Settings, Media Library, Billing, Team chat, Sticky notes and To-do are used by other
        // screens and by the floating widgets, so hiding them removes the menu entry only.)
        'tasks' => 'tasks', 'short-links' => 'short-links', 'widgets' => 'website-widget',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        if (strtolower((string) $request->header('X-Client')) === 'mobile') { return $next($request); }
        $u = auth()->user();
        if (! $u || $u->isSuperAdmin()) { return $next($request); }

        $seg = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        if (($seg[0] ?? null) === 'api') { array_shift($seg); }
        if (($seg[0] ?? null) === 'v1') { array_shift($seg); }
        $section = self::SECTIONS[$seg[0] ?? ''] ?? null;
        if (! $section) { return $next($request); }

        if (in_array($section, MobileAppConfigController::webHiddenFor($u), true)) {
            return response()->json(['message' => 'This section is not available for your account.', 'error_code' => 'section_not_available', 'section' => $section], 403);
        }

        return $next($request);
    }
}
