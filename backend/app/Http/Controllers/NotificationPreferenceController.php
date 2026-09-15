<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service notification preference toggles (CRM + HRM event types) — mobile app +
 * web Settings screen. Always self-scoped, no permission needed beyond being logged in.
 */
class NotificationPreferenceController extends Controller
{
    /** GET /notification-preferences/me */
    public function me(): JsonResponse
    {
        $user = auth()->user();
        $pref = NotificationPreference::forUser((int) $user->company_id, (int) $user->id);

        return response()->json(['data' => $pref]);
    }

    /** PUT /notification-preferences/me */
    public function update(Request $request): JsonResponse
    {
        $user = auth()->user();
        $pref = NotificationPreference::forUser((int) $user->company_id, (int) $user->id);

        $rules = [];
        foreach (NotificationPreference::TYPES as $type) {
            $rules[$type] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);

        $pref->update($data);

        return response()->json(['data' => $pref->fresh()]);
    }
}
