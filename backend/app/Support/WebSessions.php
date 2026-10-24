<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Per-browser sign-in tracking for the web portal. Each browser sends a stable id
 * (`X-Device-Id`); it is stored as a user_devices row with platform = 'web' and put in
 * the JWT as the `wuid` claim, so removing the row signs that browser out
 * (JwtMiddleware answers 401 `device_revoked`).
 *
 * The cap is companies.max_web_devices_per_user (mobile has its own, max_devices_per_user).
 */
class WebSessions
{
    public const DEFAULT_MAX = 3;

    /**
     * Registers this browser and returns a fresh JWT string carrying the `wuid` claim,
     * or a 409 JsonResponse when the user is already signed in on the maximum number of browsers.
     */
    public function admit(Request $request, User $user): string|JsonResponse
    {
        $uid = $this->uid($request);
        $max = (int) ($user->company?->max_web_devices_per_user ?? self::DEFAULT_MAX);

        $existing = UserDevice::where('user_id', $user->id)->where('device_uid', $uid)->first();
        $others = UserDevice::where('user_id', $user->id)->where('platform', 'web')->active()
            ->where('device_uid', '!=', $uid)->count();

        if ((!$existing || !$existing->isActive()) && $others >= $max) {
            $limitToken = Str::random(40);
            Cache::put("web_limit:{$limitToken}", $user->id, now()->addMinutes(10));

            return response()->json([
                'error'       => 'device_limit',
                'message'     => "You're signed in on {$max} browsers already. Sign out of one to continue.",
                'max'         => $max,
                'limit_token' => $limitToken,
                'devices'     => $this->list($user->id),
            ], 409);
        }

        UserDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_uid' => $uid],
            [
                'company_id'     => $user->company_id,
                'device_name'    => substr((string) ($request->header('X-Device-Name') ?: 'Web browser'), 0, 120),
                'platform'       => 'web',
                'ip'             => $request->ip(),
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'last_active_at' => now(),
                'revoked_at'     => null,
                'revoked_by'     => null,
            ],
        );

        return JWTAuth::customClaims(['wuid' => $uid])->fromUser($user);
    }

    /** POST /auth/web-sessions/remove — { limit_token, device_id } */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit_token' => 'required|string|size:40',
            'device_id'   => 'required|integer',
        ]);

        $userId = Cache::get("web_limit:{$data['limit_token']}");
        if (!$userId) {
            return response()->json(['message' => 'This request expired — sign in again.'], 410);
        }

        $device = UserDevice::where('user_id', $userId)->where('platform', 'web')->active()->find($data['device_id']);
        if (!$device) {
            return response()->json(['message' => 'Browser not found.'], 404);
        }
        $device->revoke($userId);

        return response()->json(['message' => 'Signed out.', 'devices' => $this->list($userId)]);
    }

    /** Frees this browser's slot on logout. */
    public function release(): void
    {
        try {
            $payload = JWTAuth::getPayload();
            if ($payload->hasKey('wuid') && auth()->id()) {
                UserDevice::where('user_id', auth()->id())->where('device_uid', $payload->get('wuid'))->active()
                    ->update(['revoked_at' => now(), 'revoked_by' => auth()->id()]);
            }
        } catch (\Throwable) {
            // token already invalid — nothing to release
        }
    }

    private function uid(Request $request): string
    {
        $id = (string) $request->header('X-Device-Id', '');
        return preg_match('/^[A-Za-z0-9-]{8,128}$/', $id) ? $id : 'web-' . Str::uuid();
    }

    private function list(int $userId): array
    {
        return UserDevice::where('user_id', $userId)->where('platform', 'web')->active()
            ->orderByDesc('last_active_at')->get()
            ->map(fn (UserDevice $d) => [
                'id'             => $d->id,
                'device_name'    => $d->device_name,
                'ip'             => $d->ip,
                'last_active_at' => $d->last_active_at?->toIso8601String(),
            ])->all();
    }
}
