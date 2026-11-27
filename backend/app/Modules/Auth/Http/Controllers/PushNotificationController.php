<?php
namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    // POST /api/v1/push/register-token
    public function registerToken(Request $request): JsonResponse
    {
        $d = $request->validate([
            'fcm_token'   => ['required','string'],
            'device_type' => ['nullable','in:android,ios,web'],
            'device_id'   => ['nullable','string','max:200'],
            'device_name' => ['nullable','string','max:120'],
        ]);

        $user  = auth()->user();
        // Phones already send X-Device-Uid on every call; web sends its own stable browser id. Either identifies "the same device".
        $d['device_id'] = $d['device_id'] ?? ($request->header('X-Device-Uid') ?: null);
        // fcm_token is globally unique, so key on it alone: when another user signs in on the same
        // browser/device the token moves to them instead of violating the unique index.
        $existing = PushToken::where('fcm_token', $d['fcm_token'])->first();
        $row = PushToken::updateOrCreate(
            ['fcm_token' => $d['fcm_token']],
            [
                // a phone handed to another person starts clean; the same person keeps their per-phone choices and its name
                'modes'        => $existing && (int) $existing->user_id === (int) $user->id ? $existing->modes : null,
                'user_id'      => $user->id,
                'company_id'   => $user->company_id,
                'device_type'  => $d['device_type'] ?? 'android',
                'device_id'    => $d['device_id'] ?? null,
                'device_name'  => $d['device_name'] ?? ($existing && (int) $existing->user_id === (int) $user->id ? $existing->device_name : null),
                'is_active'    => true,
                'last_used_at' => now(),
            ]
        );

        // A device whose token rotated (or that re-registered) must not stay listed twice: keep only the newest row per device.
        if (!empty($d['device_id'])) {
            PushToken::where('user_id', $user->id)->where('device_id', $d['device_id'])->where('id', '!=', $row->id)->where('is_active', true)->update(['is_active' => false]);
        }

        return response()->json(['message' => 'Push token registered.']);
    }

    // DELETE /api/v1/push/unregister-token
    public function unregisterToken(Request $request): JsonResponse
    {
        $request->validate(['fcm_token' => ['required','string']]);
        PushToken::where('user_id', auth()->id())
            ->where('fcm_token', $request->fcm_token)
            ->update(['is_active' => false]);
        return response()->json(['message' => 'Token unregistered.']);
    }

    // DELETE /api/v1/push/devices/{id} — remove one of MY devices from the list
    public function removeDevice(int $id): JsonResponse
    {
        PushToken::where('user_id', auth()->id())->whereKey($id)->update(['is_active' => false]);
        return response()->json(['message' => 'Device removed.']);
    }

    // DELETE /api/v1/push/devices — clear my whole device list (a browser/phone re-adds itself next time it enables notifications or signs in)
    public function clearDevices(): JsonResponse
    {
        $n = PushToken::where('user_id', auth()->id())->where('is_active', true)->update(['is_active' => false]);
        return response()->json(['message' => "{$n} device(s) cleared.", 'count' => $n]);
    }

    // GET /api/v1/push/status — config health check + this user's registered devices
    public function status(\App\Services\FirebasePushService $push): JsonResponse
    {
        $steps = $push->diagnose();
        $devices = PushToken::where('user_id', auth()->id())->where('is_active', true)
            ->orderByDesc('last_used_at')->get(['id', 'device_type', 'device_id', 'last_used_at']);

        return response()->json([
            'configured' => collect($steps)->every(fn ($s) => $s['ok']),
            'steps'      => $steps,
            'devices'    => $devices,
        ]);
    }

    // POST /api/v1/push/test — send a real test notification to this user's devices
    public function test(\App\Services\FirebasePushService $push): JsonResponse
    {
        try {
            $results = $push->sendTest((int) auth()->id());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not reach Firebase: ' . $e->getMessage()], 502);
        }
        if (!$results) {
            return response()->json(['message' => 'No registered device yet — allow notifications in the browser or open the mobile app, then try again.', 'results' => []], 422);
        }
        return response()->json(['results' => $results]);
    }

    // GET /api/v1/push/history
    public function history(): JsonResponse
    {
        $logs = \App\Models\PushNotification::where('user_id', auth()->id())
            ->latest()->limit(50)->get();
        return response()->json(['notifications' => $logs]);
    }
}
