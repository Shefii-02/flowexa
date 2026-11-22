<?php

namespace App\Support;

use App\Events\DeviceRevoked;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Phone-app devices for the EMAIL + PASSWORD login — the same rules the QR / PIN login already had (DeviceAuthController::claim):
 * every phone is a user_devices row, the account is capped at companies.max_devices_per_user, and when the cap is hit the person
 * picks one phone to remove and carries on straight into the app. The token carries the `duid` claim, so removing a phone from
 * another one signs it out (JwtMiddleware answers 401 device_revoked).
 *
 * An app build that sends no device_uid keeps the old behaviour (no tracking) so a half-updated rollout never locks anyone out.
 */
class MobileDevices
{
    public const DEFAULT_MAX = 2;

    /**
     * @return string|JsonResponse|null  a fresh JWT carrying `duid` · a 409 `device_limit` response · null when the app sent no device id
     */
    public function admit(Request $request, User $user): string|JsonResponse|null
    {
        $uid = trim((string) $request->input('device_uid', ''));
        if ($uid === '' || !preg_match('/^[A-Za-z0-9-]{8,128}$/', $uid)) {
            return null;
        }

        $max      = (int) ($user->company?->max_devices_per_user ?? self::DEFAULT_MAX);
        $existing = UserDevice::where('user_id', $user->id)->where('device_uid', $uid)->first();

        if (!$existing || !$existing->isActive()) {
            $others = UserDevice::where('user_id', $user->id)->where('platform', '!=', 'web')->active()->where('device_uid', '!=', $uid)->count();
            if ($others >= $max) {
                $limitToken = Str::random(40);
                Cache::put("mobile_limit:{$limitToken}", $user->id, now()->addMinutes(10));

                return response()->json([
                    'error'       => 'device_limit',
                    'message'     => "This account is already signed in on {$max} phone" . ($max === 1 ? '' : 's') . '. Remove one to continue.',
                    'max'         => $max,
                    'limit_token' => $limitToken,
                    'devices'     => $this->list($user->id),
                ], 409);
            }
        }

        $platform = in_array($request->input('platform'), ['ios', 'android', 'other'], true) ? $request->input('platform') : 'other';

        UserDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_uid' => $uid],
            array_filter([
                'company_id'     => $user->company_id,
                'device_name'    => substr((string) ($request->input('device_name') ?: 'Mobile device'), 0, 120),
                'platform'       => $platform,
                'app_version'    => $request->input('app_version') ? substr((string) $request->input('app_version'), 0, 20) : null,
                'push_token'     => $request->input('push_token') ? substr((string) $request->input('push_token'), 0, 255) : null,
                'ip'             => $request->ip(),
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'last_active_at' => now(),
            ], fn ($v) => $v !== null) + ['revoked_at' => null, 'revoked_by' => null],
        );

        $token = JWTAuth::customClaims(['duid' => $uid])->fromUser($user);
        JWTAuth::customClaims([]);   // the JWT instance is shared — never let this device id leak into the next token made in a long-running process
        return $token;
    }

    /** POST /auth/mobile-devices/remove — { limit_token, device_id } frees one phone slot; the app then repeats the login. */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit_token' => 'required|string|size:40',
            'device_id'   => 'required|integer',
        ]);

        $userId = Cache::get("mobile_limit:{$data['limit_token']}");
        if (!$userId) {
            return response()->json(['message' => 'This request expired — sign in again.'], 410);
        }

        $device = UserDevice::where('user_id', $userId)->where('platform', '!=', 'web')->active()->find($data['device_id']);
        if (!$device) {
            return response()->json(['message' => 'That phone is not signed in on this account.'], 404);
        }
        $device->revoke($userId);
        broadcast(new DeviceRevoked($device->user_id, $device->id, $device->device_uid));

        return response()->json(['message' => 'Phone removed — you can finish signing in now.', 'devices' => $this->list($userId)]);
    }

    private function list(int $userId): array
    {
        return UserDevice::where('user_id', $userId)->where('platform', '!=', 'web')->active()
            ->orderByDesc('last_active_at')->get()
            ->map(fn (UserDevice $d) => [
                'id'             => $d->id,
                'device_name'    => $d->device_name,
                'platform'       => $d->platform,
                'last_active_at' => $d->last_active_at?->toIso8601String(),
            ])->all();
    }
}
