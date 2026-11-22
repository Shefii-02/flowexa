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

        return response()->json([
            'user_id'       => $user->id,
            'portal_access' => (bool) $user->portal_access,
            'mobile_access' => (bool) $user->mobile_access,
            // null = this person follows the company's mobile layout; otherwise the screens picked for them alone
            'mobile_screens' => $user->mobile_screens,
            'tab_labels' => \App\Http\Controllers\MobileAppConfigController::TABS,
            'profile_item_labels' => \App\Http\Controllers\MobileAppConfigController::PROFILE_ITEMS,
            'catalogue' => app(\App\Http\Controllers\MobileModulesController::class)->catalogue(),
            'main'          => $rows->firstWhere('is_main', true)?->section_key,
            'subs'          => $rows->where('is_main', false)->pluck('section_key')->values(),
            // Role + sections = what this person can actually do (see User::effectivePermissions()).
            'role_permissions'      => array_values((array) ($user->role?->permissions ?? [])),
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
            'main'          => ['nullable', Rule::in($keys)],
            'subs'          => ['present', 'array'],
            'subs.*'        => [Rule::in($keys)],
            'mobile_screens'                => ['sometimes', 'nullable', 'array'],
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
            if (array_key_exists('mobile_screens', $data)) {
                $ms = $data['mobile_screens'];
                $valid = array_column(app(\App\Http\Controllers\MobileModulesController::class)->catalogue(), 'id');
                $user->update(['mobile_screens' => $ms === null ? null : [
                    'tabs' => array_intersect_key(array_map('boolval', $ms['tabs']), \App\Http\Controllers\MobileAppConfigController::TABS),
                    'profile_items' => array_intersect_key(array_map('boolval', $ms['profile_items']), \App\Http\Controllers\MobileAppConfigController::PROFILE_ITEMS),
                    'pages' => array_values(array_intersect(array_unique($ms['pages']), $valid)),
                ]]);
            }
            StaffAccessSection::where('user_id', $user->id)->delete();

            $subs = collect($data['subs'])->unique()->reject(fn ($k) => $k === ($data['main'] ?? null));
            if (!empty($data['main'])) {
                StaffAccessSection::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'section_key' => $data['main'], 'is_main' => true]);
            }
            foreach ($subs as $k) {
                StaffAccessSection::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'section_key' => $k, 'is_main' => false]);
            }
        });

        return response()->json(['message' => 'Access updated.']);
    }
}
