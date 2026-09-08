<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Events\DeviceLoginClaimed;
use App\Events\DeviceRevoked;
use App\Http\Controllers\Controller;
use App\Models\DeviceLoginToken;
use App\Models\User;
use App\Models\UserDevice;
use App\Modules\Auth\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Public endpoints the mobile app calls to log in via a QR / PIN challenge
 * generated on the web. No JWT on `resolve` / `claim` / `remove-device` — the
 * challenge itself is the credential. `status` / `heartbeat` do require the JWT
 * the claim issued.
 */
class DeviceAuthController extends Controller
{
    /** Resolve a challenge by its QR token or its 6-digit PIN. */
    private function resolve(Request $request): DeviceLoginToken
    {
        $data = $request->validate([
            'token' => 'nullable|string|size:40',
            'pin'   => 'nullable|string|size:6',
        ]);

        $q = DeviceLoginToken::query()->where('status', 'pending');
        if (!empty($data['token'])) {
            $q->where('token', $data['token']);
        } elseif (!empty($data['pin'])) {
            $q->where('pin', $data['pin']);
        } else {
            throw ValidationException::withMessages(['token' => 'Provide a QR token or a PIN.']);
        }

        $challenge = $q->latest('id')->first();

        if (!$challenge) {
            throw ValidationException::withMessages(['token' => 'Invalid or already-used code.']);
        }
        if ($challenge->expires_at->isPast()) {
            $challenge->update(['status' => 'expired']);
            throw ValidationException::withMessages(['token' => 'This code has expired — refresh it on the web.']);
        }

        return $challenge;
    }

    /** GET-ish check that a code is still valid before showing the confirm screen. */
    public function check(Request $request): JsonResponse
    {
        $challenge = $this->resolve($request);

        return response()->json([
            'ok'    => true,
            'user'  => ['name' => $challenge->user->name, 'email' => $challenge->user->email],
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ]);
    }

    /** POST /device-auth/claim — link this device and log in. */
    public function claim(Request $request): JsonResponse
    {
        $device = $request->validate([
            'device_uid'  => 'required|string|max:128',
            'device_name' => 'nullable|string|max:120',
            'platform'    => 'nullable|in:ios,android,web,other',
            'app_version' => 'nullable|string|max:20',
            'push_token'  => 'nullable|string|max:255',
        ]);

        $challenge = $this->resolve($request);
        $user = $challenge->user;

        $existing = UserDevice::where('user_id', $user->id)->where('device_uid', $device['device_uid'])->first();
        $max = (int) ($user->company?->max_devices_per_user ?? 2);

        if (!$existing) {
            $activeCount = UserDevice::where('user_id', $user->id)->active()->count();
            if ($activeCount >= $max) {
                return response()->json([
                    'error'   => 'device_limit',
                    'message' => "This account already has {$max} linked devices. Remove one to continue.",
                    'max'     => $max,
                    'devices' => UserDevice::where('user_id', $user->id)->active()
                        ->orderByDesc('last_active_at')->get()
                        ->map(fn (UserDevice $d) => [
                            'id'             => $d->id,
                            'device_name'    => $d->device_name,
                            'platform'       => $d->platform,
                            'last_active_at' => $d->last_active_at?->toIso8601String(),
                        ]),
                    // pass one of these back to /device-auth/remove-device, then retry claim
                    'retry_with' => ['token' => $request->input('token'), 'pin' => $request->input('pin')],
                ], 409);
            }
        }

        $row = UserDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_uid' => $device['device_uid']],
            [
                'company_id'     => $user->company_id,
                'device_name'    => $device['device_name'] ?? 'Mobile device',
                'platform'       => $device['platform'] ?? 'other',
                'app_version'    => $device['app_version'] ?? null,
                'push_token'     => $device['push_token'] ?? null,
                'ip'             => $request->ip(),
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'login_token_id' => $challenge->id,
                'last_active_at' => now(),
                'revoked_at'     => null,
                'revoked_by'     => null,
            ],
        );

        $challenge->update([
            'status'            => 'claimed',
            'claimed_device_id' => $row->id,
            'claimed_at'        => now(),
        ]);

        broadcast(new DeviceLoginClaimed($user->id, $challenge->id, $row));

        $token = JWTAuth::customClaims(['duid' => $row->device_uid])->fromUser($user);

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
            'user'         => new UserResource($user->load('role', 'company')),
            'device'       => ['id' => $row->id, 'device_uid' => $row->device_uid, 'device_name' => $row->device_name],
        ]);
    }

    /** POST /device-auth/remove-device — free a slot when the limit is hit. */
    public function removeDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => 'required|integer']);
        $challenge = $this->resolve($request);

        $device = UserDevice::where('user_id', $challenge->user_id)->where('id', $data['device_id'])->first();
        if (!$device) {
            throw ValidationException::withMessages(['device_id' => 'That device is not on this account.']);
        }

        if ($device->isActive()) {
            $device->revoke();
            broadcast(new DeviceRevoked($device->user_id, $device->id, $device->device_uid));
        }

        return response()->json(['ok' => true, 'message' => 'Device removed — you can finish signing in now.']);
    }

    // ── Authenticated (the JWT the claim issued) ───────────────────────────

    /** GET /device-auth/status — the app calls this on every launch. */
    public function status(Request $request): JsonResponse
    {
        $user = auth()->user();
        $uid  = $request->header('X-Device-Uid') ?: $request->input('device_uid');

        if (!$uid) {
            return response()->json(['valid' => true, 'note' => 'no device uid supplied']);
        }

        $device = UserDevice::where('user_id', $user->id)->where('device_uid', $uid)->first();
        $valid = $device && $device->isActive();

        if ($valid) {
            $device->update(['last_active_at' => now()]);
        }

        return response()->json([
            'valid'  => (bool) $valid,
            'reason' => $valid ? null : ($device ? 'revoked' : 'unknown_device'),
        ]);
    }

    /** POST /device-auth/heartbeat — keep last_active_at fresh + refresh push token. */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = auth()->user();
        $uid  = $request->header('X-Device-Uid') ?: $request->input('device_uid');
        $device = $uid ? UserDevice::where('user_id', $user->id)->where('device_uid', $uid)->active()->first() : null;

        if (!$device) {
            return response()->json(['valid' => false], 200);
        }

        $device->update(array_filter([
            'last_active_at' => now(),
            'push_token'     => $request->input('push_token'),
            'app_version'    => $request->input('app_version'),
        ], fn ($v) => $v !== null));

        return response()->json(['valid' => true]);
    }

    /** POST /device-auth/logout — the app signs itself out on this device. */
    public function logout(Request $request): JsonResponse
    {
        $user = auth()->user();
        $uid  = $request->header('X-Device-Uid') ?: $request->input('device_uid');
        $device = $uid ? UserDevice::where('user_id', $user->id)->where('device_uid', $uid)->first() : null;

        if ($device && $device->isActive()) {
            $device->revoke($user->id);
            broadcast(new DeviceRevoked($device->user_id, $device->id, $device->device_uid));
        }
        try { JWTAuth::invalidate(JWTAuth::getToken()); } catch (\Throwable) {}

        return response()->json(['message' => 'Signed out on this device.']);
    }
}
