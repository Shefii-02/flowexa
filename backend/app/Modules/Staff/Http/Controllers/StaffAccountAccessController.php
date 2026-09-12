<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Models\MetaAdAccount;
use App\Models\StaffAccountAccess;
use App\Models\User;
use App\Models\WaPhoneNumber;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lets an admin/owner restrict a staff member to specific connected accounts —
 * WA Chat sessions, WA Cloud numbers, Instagram accounts, Meta Ads accounts —
 * instead of the company-wide default (see StaffAccountAccess / User::allowedAccountIds()).
 * A staff member with no rows for a type stays unrestricted for that type.
 */
class StaffAccountAccessController extends Controller
{
    /** GET /staff/account-access/options — everything the picker UI needs in one call. */
    public function options(): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        return response()->json([
            'staff' => User::where('company_id', $companyId)->where('is_active', true)
                ->with('role:id,name,label')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role_id']),

            'accounts' => [
                'wa_session' => WahaSession::where('company_id', $companyId)
                    ->orderBy('session_name')
                    ->get(['id', 'session_name', 'display_name', 'status'])
                    ->map(fn ($s) => ['id' => $s->id, 'label' => $s->display_name ?: $s->session_name, 'sub' => $s->status]),

                'phone_number' => WaPhoneNumber::where('company_id', $companyId)
                    ->orderBy('label')
                    ->get(['id', 'label', 'display_number', 'is_active'])
                    ->map(fn ($n) => ['id' => $n->id, 'label' => $n->label ?: $n->display_number, 'sub' => $n->display_number]),

                'instagram_account' => InstagramAccount::where('company_id', $companyId)
                    ->orderBy('username')
                    ->get(['id', 'username', 'name'])
                    ->map(fn ($a) => ['id' => $a->id, 'label' => $a->name ?: "@{$a->username}", 'sub' => "@{$a->username}"]),

                'meta_ads_account' => MetaAdAccount::where('company_id', $companyId)
                    ->orderBy('ad_account_name')
                    ->get(['id', 'ad_account_name', 'ad_account_id'])
                    ->map(fn ($a) => ['id' => $a->id, 'label' => $a->ad_account_name ?: $a->ad_account_id, 'sub' => $a->ad_account_id]),
            ],
        ]);
    }

    /** GET /staff/{user}/account-access — this staff member's current grants, by type. */
    public function show(int $userId): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        User::where('company_id', $companyId)->findOrFail($userId);

        $rows = StaffAccountAccess::where('user_id', $userId)->get(['account_type', 'account_id']);

        $access = collect(StaffAccountAccess::TYPES)->mapWithKeys(
            fn ($type) => [$type => $rows->where('account_type', $type)->pluck('account_id')->values()]
        );

        return response()->json(['user_id' => $userId, 'access' => $access]);
    }

    /** PUT /staff/{user}/account-access — replace the grant set for one account_type. */
    public function update(Request $request, int $userId): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        User::where('company_id', $companyId)->findOrFail($userId);

        $data = $request->validate([
            'account_type'   => ['required', Rule::in(StaffAccountAccess::TYPES)],
            'account_ids'    => ['present', 'array'],
            'account_ids.*'  => ['integer'],
        ]);

        DB::transaction(function () use ($companyId, $userId, $data) {
            StaffAccountAccess::where('user_id', $userId)->where('account_type', $data['account_type'])->delete();
            foreach (array_unique($data['account_ids']) as $accountId) {
                StaffAccountAccess::create([
                    'company_id'   => $companyId,
                    'user_id'      => $userId,
                    'account_type' => $data['account_type'],
                    'account_id'   => $accountId,
                    'created_at'   => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Account access updated.']);
    }
}
