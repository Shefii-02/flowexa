<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a COMPANY shows in the mobile app, separately for STAFF and for TEAM HEADS — stored in companies.settings['mobile_app'], on top of the superadmin's platform-wide
 * hidden tiles. Three things: which bottom tabs exist (Home and Profile are always there), which Profile menu items show, and which Explore
 * tiles are hidden. Web is unaffected; hidden pages stay reachable by link, only their entry points disappear.
 *
 *   GET /mobile-config            any signed-in user — the app reads this on start
 *   GET|PUT /settings/mobile-app  company admin (settings.manage)
 */
class MobileAppConfigController extends Controller
{
    public const TABS = [
        'todo' => 'To-do list', 'chats' => 'Chats', 'explore' => 'Explore', 'attendance' => 'Attendance', 'payroll' => 'Payroll',
    ];
    public const PROFILE_ITEMS = [
        'personal' => 'Personal details', 'documents' => 'Documents', 'leave' => 'Leave', 'devices' => 'Linked devices',
        'app_lock' => 'App lock PIN', 'settings' => 'Settings', 'notifications' => 'Notifications', 'help' => 'Help and support',
    ];

    /** One audience's saved layout with defaults filled in (everything shown unless switched off). Old flat configs count for both audiences. */
    private function layout(array $saved, string $audience): array
    {
        $own = isset($saved['staff']) || isset($saved['team_head']) ? (array) ($saved[$audience] ?? []) : $saved;
        $tabs = []; $items = [];
        foreach (self::TABS as $k => $_) { $tabs[$k] = (bool) ($own['tabs'][$k] ?? true); }
        foreach (self::PROFILE_ITEMS as $k => $_) { $items[$k] = (bool) ($own['profile_items'][$k] ?? true); }

        return [
            'tabs' => $tabs, 'profile_items' => $items, 'hidden_modules' => array_values((array) ($own['hidden_modules'] ?? [])),
            'hidden_sections' => array_values((array) ($own['hidden_sections'] ?? [])),   // WEB menu sections this audience doesn't see
        ];
    }

    private function saved(): array
    {
        return (array) (auth()->user()->company?->settings['mobile_app'] ?? []);
    }

    /**
     * What THIS user gets. Staff get the staff layout. A team head (company admin / owner / team lead / anyone who can see the team) is
     * both a team head and a member of staff, so they get the two layouts combined: a tab or menu item shows if either layout shows it,
     * and an Explore tile is hidden only if both hide it.
     */
    private function effective(): array
    {
        $saved = $this->saved();
        $staff = $this->layout($saved, 'staff');
        if (! \App\Modules\Analytics\Http\Controllers\TeamMembersController::canSeeTeam(auth()->user()->loadMissing('role'))) {
            return $staff + ['audience' => 'staff'];
        }
        $head = $this->layout($saved, 'team_head');

        return [
            'tabs' => array_map(fn ($k) => $staff['tabs'][$k] || $head['tabs'][$k], array_combine(array_keys(self::TABS), array_keys(self::TABS))),
            'profile_items' => array_map(fn ($k) => $staff['profile_items'][$k] || $head['profile_items'][$k], array_combine(array_keys(self::PROFILE_ITEMS), array_keys(self::PROFILE_ITEMS))),
            'hidden_modules' => array_values(array_intersect($staff['hidden_modules'], $head['hidden_modules'])),
            'hidden_sections' => array_values(array_intersect($staff['hidden_sections'], $head['hidden_sections'])),
            'audience' => 'team_head',
        ];
    }

    /**
     * Narrow the company layout down to the screens picked for this one person (Staff Access → Mobile screens). `pages` is an allowlist of
     * Explore tiles, so anything outside it is hidden; tabs and Profile items can only be switched off here, never on.
     */
    private function withPersonal(array $cfg): array
    {
        $mine = auth()->user()->mobile_screens;
        if (! is_array($mine)) { return $cfg; }
        foreach ($cfg['tabs'] as $k => $on) { $cfg['tabs'][$k] = $on && (bool) ($mine['tabs'][$k] ?? true); }
        foreach ($cfg['profile_items'] as $k => $on) { $cfg['profile_items'][$k] = $on && (bool) ($mine['profile_items'][$k] ?? true); }
        $allowed = (array) ($mine['pages'] ?? []);
        $all = array_column(app(MobileModulesController::class)->catalogue(), 'id');
        $cfg['hidden_modules'] = array_values(array_unique(array_merge($cfg['hidden_modules'], array_diff($all, $allowed))));
        $cfg['personal'] = true;

        return $cfg;
    }

    // GET /mobile-config
    public function show(): JsonResponse
    {
        return response()->json($this->withPersonal($this->effective()));
    }

    // GET /web-config — the WEB menu sections to hide for this user: the superadmin's, then the company's (staff / team head), then their own
    public function web(): JsonResponse
    {
        return response()->json(['hidden' => self::webHiddenFor(auth()->user())]);
    }

    /** Web menu sections hidden for [$user]: the superadmin's, then the company's (staff / team head), then their own choice. Used by the sidebar AND the API gate. */
    public static function webHiddenFor($user): array
    {
        if ($user->isSuperAdmin()) { return []; }   // the platform owner sees everything
        $self = new self();
        $hidden = array_merge(app(MobileModulesController::class)->webHiddenIds(), $self->effective()['hidden_sections']);
        $mine = $user->web_screens;
        if (is_array($mine) && is_array($mine['sections'] ?? null)) {
            $hidden = array_merge($hidden, array_diff(array_keys(MobileModulesController::WEB_SECTIONS), $mine['sections']));
        }

        return array_values(array_unique($hidden));
    }

    // GET /settings/mobile-app
    public function edit(): JsonResponse
    {
        $saved = $this->saved();

        return response()->json([
            'staff' => $this->layout($saved, 'staff'), 'team_head' => $this->layout($saved, 'team_head'),
            'tab_labels' => self::TABS, 'profile_item_labels' => self::PROFILE_ITEMS,
            'catalogue' => app(MobileModulesController::class)->allowedCatalogue(),   // only what the superadmin allows
            'web_sections' => app(MobileModulesController::class)->allowedWebSections(),
        ]);
    }

    // PUT /settings/mobile-app
    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (['staff', 'team_head'] as $a) {
            $rules += [
                "$a" => ['required', 'array'],
                "$a.tabs" => ['required', 'array'], "$a.tabs.*" => ['boolean'],
                "$a.profile_items" => ['required', 'array'], "$a.profile_items.*" => ['boolean'],
                "$a.hidden_modules" => ['present', 'array'], "$a.hidden_modules.*" => ['string', 'max:100'],
                "$a.hidden_sections" => ['sometimes', 'array'], "$a.hidden_sections.*" => ['string', 'max:50'],
            ];
        }
        $d = $request->validate($rules);
        $valid = array_column(app(MobileModulesController::class)->allowedCatalogue(), 'id');
        $validSections = array_column(app(MobileModulesController::class)->allowedWebSections(), 'id');
        $company = auth()->user()->company;
        $settings = (array) ($company->settings ?? []);
        $out = [];
        foreach (['staff', 'team_head'] as $a) {
            $out[$a] = [
                'tabs' => array_intersect_key(array_map('boolval', $d[$a]['tabs']), self::TABS),
                'profile_items' => array_intersect_key(array_map('boolval', $d[$a]['profile_items']), self::PROFILE_ITEMS),
                'hidden_modules' => array_values(array_intersect(array_unique($d[$a]['hidden_modules']), $valid)),
                'hidden_sections' => array_values(array_intersect(array_unique($d[$a]['hidden_sections'] ?? []), $validSections)),
            ];
        }
        $settings['mobile_app'] = $out;
        $company->update(['settings' => $settings]);

        return $this->edit();
    }
}
