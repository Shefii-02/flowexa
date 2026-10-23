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
            'main'          => $rows->firstWhere('is_main', true)?->section_key,
            'subs'          => $rows->where('is_main', false)->pluck('section_key')->values(),
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
        ]);

        if ($user->id === $actor->id && !$data['portal_access'] && !$actor->isOwner()) {
            return response()->json(['message' => 'You cannot turn off your own web portal access.'], 422);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update(['portal_access' => $data['portal_access'], 'mobile_access' => $data['mobile_access']]);
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
