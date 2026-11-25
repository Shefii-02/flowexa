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

    /** GET /notification-preferences/devices — my phones / browsers, each with its own overrides. */
    public function devices(Request $request): JsonResponse
    {
        $mine = (string) $request->query('fcm_token', '');   // the app passes its own token so it can tell which row is this phone
        $rows = \App\Models\PushToken::where('user_id', auth()->id())->where('is_active', true)->orderByDesc('last_used_at')->get()
            ->map(fn ($t) => ['id' => $t->id, 'current' => $mine !== '' && hash_equals($t->fcm_token, $mine), 'name' => $t->device_name, 'type' => $t->device_type, 'last_used_at' => $t->last_used_at?->toIso8601String(), 'modes' => (object) ($t->modes ?? [])]);
        return response()->json(['data' => $rows]);
    }

    /** PUT /notification-preferences/devices/{id} — overrides for one of MY phones (an empty list = follow my own settings again). */
    public function updateDevice(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['modes' => ['present', 'array'], 'device_name' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $t = \App\Models\PushToken::where('user_id', auth()->id())->whereKey($id)->firstOrFail();
        $t->update(['modes' => NotificationPreference::cleanModes($d['modes']) ?: null] + (array_key_exists('device_name', $d) ? ['device_name' => $d['device_name']] : []));
        return response()->json(['data' => ['id' => $t->id, 'modes' => (object) ($t->modes ?? [])]]);
    }

    /** DELETE /notification-preferences/devices/{id} — stop sending to that phone. */
    public function removeDevice(int $id): JsonResponse
    {
        \App\Models\PushToken::where('user_id', auth()->id())->whereKey($id)->update(['is_active' => false]);
        return response()->json(['message' => 'Device removed.']);
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
        $rules['modes'] = ['sometimes', 'array'];
        $data = $request->validate($rules);
        if (array_key_exists('modes', $data)) { $data['modes'] = NotificationPreference::cleanModes($data['modes']); }

        $pref->update($data);

        return response()->json(['data' => $pref->fresh()]);
    }
}
