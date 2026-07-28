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
        ]);

        $user  = auth()->user();
        PushToken::updateOrCreate(
            ['user_id' => $user->id, 'fcm_token' => $d['fcm_token']],
            [
                'company_id'   => $user->company_id,
                'device_type'  => $d['device_type'] ?? 'android',
                'device_id'    => $d['device_id'] ?? null,
                'is_active'    => true,
                'last_used_at' => now(),
            ]
        );

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

    // GET /api/v1/push/history
    public function history(): JsonResponse
    {
        $logs = \App\Models\PushNotification::where('user_id', auth()->id())
            ->latest()->limit(50)->get();
        return response()->json(['notifications' => $logs]);
    }
}
