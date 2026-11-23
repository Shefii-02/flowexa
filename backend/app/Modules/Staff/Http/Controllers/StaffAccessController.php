<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StaffAccessSection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Per-staff access: which prebuilt access sections (config/access_sections.php) they hold — one main plus
 * any number of sub sections — and whether they may use the web portal and/or the mobile app.
 * Section permissions are added on top of the staff member's role (see User::effectivePermissions()).
 */
class StaffAccessController extends Controller
{
    /** GET /staff/access/sections — the prebuilt catalogue. */
    public function sections(): JsonResponse
    {
        $out = [];
        foreach (config('access_sections') as $key => $s) {
            $out[] = ['key' => $key, 'label' => $s['label'], 'icon' => $s['icon'], 'color' => $s['color'],
                      'description' => $s['description'], 'permissions' => $s['permissions']];
        }
        return response()->json(['sections' => $out]);
    }

    /** GET /staff/{user}/access */
    public function show(int $userId): JsonResponse
    {
        $user = User::where('company_id', auth()->user()->company_id)->findOrFail($userId);
        $rows = StaffAccessSection::where('user_id', $user->id)->get();
        // No sections of their own → they follow the role's defaults (Roles → Access sections), including when the role changes or is edited.
        $follows = $rows->isEmpty();
        $role = $user->role;

        return response()->json([
            'user_id'       => $user->id,
            'portal_access' => (bool) $user->portal_access,
            'mobile_access' => (bool) $user->mobile_access,
            // null = this person follows the company's mobile layout; otherwise the screens picked for them alone
            'mobile_screens' => $user->mobile_screens,
            'web_screens' => $user->web_screens,   // null = follows the company's web menu; managed separately from the app
            'tab_labels' => \App\Http\Controllers\MobileAppConfigController::TABS,
            'profile_item_labels' => \App\Http\Controllers\MobileAppConfigController::PROFILE_ITEMS,
            'catalogue' => app(\App\Http\Controllers\MobileModulesController::class)->allowedCatalogue(),   // only what the superadmin allows
            'web_sections' => app(\App\Http\Controllers\MobileModulesController::class)->allowedWebSections(),
            'follows_role'  => $follows,
            'role_access'   => ['main' => $role?->access_main, 'subs' => array_values((array) ($role?->access_subs ?? []))],
            'main'          => $follows ? $role?->access_main : $rows->firstWhere('is_main', true)?->section_key,
            'subs'          => $follows ? array_values((array) ($role?->access_subs ?? [])) : $rows->where('is_main', false)->pluck('section_key')->values(),
            // Role + sections = what this person can actually do (see User::effectivePermissions()).
            'role_permissions'      => array_values((array) ($user->role?->permissions ?? [])),
            // permissions this person has from their sections (not from the role) and the extra ones granted to them alone
            'section_permissions'   => $this->sectionPermissions($user),
            'extra_permissions'     => array_values((array) ($user->extra_permissions ?? [])),
            'permission_catalogue'  => \App\Models\Permission::orderBy('group')->orderBy('sort_order')->get(['key', 'label', 'group', 'type'])->all(),
            'effective_permissions' => $user->effectivePermissions(),
            'all_permissions'       => \App\Models\Permission::pluck('key')->all(),
        ]);
    }

    /** PUT /staff/{user}/access */
    public function update(Request $request, int $userId): JsonResponse
    {
        $actor = auth()->user();
        $user  = User::where('company_id', $actor->company_id)->findOrFail($userId);
        $keys  = array_keys(config('access_sections'));

        $data = $request->validate([
            'portal_access' => ['required', 'boolean'],
            'mobile_access' => ['required', 'boolean'],
            'extra_permissions'   => ['sometimes', 'array'],
            'extra_permissions.*' => ['string', 'max:100'],
            'follow_role'   => ['sometimes', 'boolean'],   // true = drop their own sections and follow the role's defaults
            'main'          => ['sometimes', 'nullable', Rule::in($keys)],
            'subs'          => ['sometimes', 'array'],
            'subs.*'        => [Rule::in($keys)],
            'mobile_screens'                => ['sometimes', 'nullable', 'array'],
            'web_screens'                   => ['sometimes', 'nullable', 'array'],
            'web_screens.sections'          => ['required_with:web_screens', 'array'],
            'web_screens.sections.*'        => ['string', 'max:50'],
            'mobile_screens.tabs'           => ['required_with:mobile_screens', 'array'],
            'mobile_screens.tabs.*'         => ['boolean'],
            'mobile_screens.profile_items'  => ['required_with:mobile_screens', 'array'],
            'mobile_screens.profile_items.*' => ['boolean'],
            'mobile_screens.pages'          => ['present_with:mobile_screens', 'array'],
            'mobile_screens.pages.*'        => ['string', 'max:100'],
        ]);

        if ($user->id === $actor->id && !$data['portal_access'] && !$actor->isOwner()) {
            return response()->json(['message' => 'You cannot turn off your own web portal access.'], 422);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update(['portal_access' => $data['portal_access'], 'mobile_access' => $data['mobile_access']]);
            if (array_key_exists('extra_permissions', $data)) {
                // Only real permissions, and nobody can hand out more than they hold themselves (owners / superadmins excepted).
                $valid = \App\Models\Permission::pluck('key')->all();
                $allowed = ($actor->isSuperAdmin() || $actor->isOwner()) ? $valid : array_intersect($valid, $actor->effectivePermissions());
                $user->update(['extra_permissions' => array_values(array_intersect(array_unique($data['extra_permissions']), $allowed))]);
            }
            if (array_key_exists('web_screens', $data)) {
                $ws = $data['web_screens'];
                $user->update(['web_screens' => $ws === null ? null : ['sections' => array_values(array_intersect(array_unique($ws['sections']), array_column(app(\App\Http\Controllers\MobileModulesController::class)->allowedWebSections(), 'id')))]]);
            }
            if (array_key_exists('mobile_screens', $data)) {
                $ms = $data['mobile_screens'];
                $valid = array_column(app(\App\Http\Controllers\MobileModulesController::class)->allowedCatalogue(), 'id');
                $user->update(['mobile_screens' => $ms === null ? null : [
                    'tabs' => array_intersect_key(array_map('boolval', $ms['tabs']), \App\Http\Controllers\MobileAppConfigController::TABS),
                    'profile_items' => array_intersect_key(array_map('boolval', $ms['profile_items']), \App\Http\Controllers\MobileAppConfigController::PROFILE_ITEMS),
                    'pages' => array_values(array_intersect(array_unique($ms['pages']), $valid)),
                ]]);
            }
            StaffAccessSection::where('user_id', $user->id)->delete();
            if ($data['follow_role'] ?? false) { return; }   // no rows → the role's sections apply

            $subs = collect($data['subs'] ?? [])->unique()->reject(fn ($k) => $k === ($data['main'] ?? null));
            if (!empty($data['main'])) {
                StaffAccessSection::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'section_key' => $data['main'], 'is_main' => true]);
            }
            foreach ($subs as $k) {
                StaffAccessSection::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'section_key' => $k, 'is_main' => false]);
            }
        });

        return response()->json(['message' => 'Access updated.']);
    }

    /** Permissions that come from this person's access sections (main + subs, or the role's defaults) — not counting the role's own list. */
    private function sectionPermissions(User $user): array
    {
        $all = \App\Models\Permission::pluck('key')->all();
        $out = [];
        foreach ($user->accessSectionKeys() as $key) {
            $p = (array) config("access_sections.$key.permissions", []);
            $out = array_merge($out, in_array('*', $p, true) ? $all : $p);
        }

        return array_values(array_unique($out));
    }
}
