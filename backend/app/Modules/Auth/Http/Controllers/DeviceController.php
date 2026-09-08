<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Events\DeviceRevoked;
use App\Http\Controllers\Controller;
use App\Models\DeviceLoginToken;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web-side (JWT) linked-devices management. A logged-in user manages their own
 * devices; a staff manager can also link / unlink devices for their team.
 */
class DeviceController extends Controller
{
    private function me(): User
    {
        return auth()->user();
    }

    /** Can this user VIEW other staff members' linked devices? */
    private function canViewStaffDevices(): bool
    {
        $u = $this->me();
        return $u->isOwner() || $u->isSuperAdmin()
            || $u->hasAnyPermission(['devices.view', 'devices.manage', 'staff.view', 'staff.manage']);
    }

    /** Can this user LINK / UNLINK devices on behalf of other staff members? */
    private function canManageStaffDevices(): bool
    {
        $u = $this->me();
        return $u->isOwner() || $u->isSuperAdmin()
            || $u->hasAnyPermission(['devices.manage', 'staff.manage']);
    }

    private function deviceList(int $userId): array
    {
        return UserDevice::where('user_id', $userId)
            ->orderByDesc('last_active_at')->orderByDesc('id')
            ->get()
            ->map(fn (UserDevice $d) => [
                'id'             => $d->id,
                'device_name'    => $d->device_name,
                'platform'       => $d->platform,
                'app_version'    => $d->app_version,
                'ip'             => $d->ip,
                'last_active_at' => $d->last_active_at?->toIso8601String(),
                'linked_at'      => $d->created_at->toIso8601String(),
                'active'         => $d->isActive(),
                'revoked_at'     => $d->revoked_at?->toIso8601String(),
            ])->all();
    }

    // ── GET /devices — my linked devices ────────────────────────────────────
    public function index(): JsonResponse
    {
        $user = $this->me();
        $active = UserDevice::where('user_id', $user->id)->active()->count();

        return response()->json([
            'max'      => (int) ($user->company->max_devices_per_user ?? 2),
            'active'   => $active,
            'devices'  => $this->deviceList($user->id),
        ]);
    }

    // ── GET /devices/staff — team device overview (managers) ────────────────
    public function staff(): JsonResponse
    {
        abort_unless($this->canViewStaffDevices(), 403, 'No staff permission.');
        $companyId = $this->me()->company_id;
        $max = (int) ($this->me()->company->max_devices_per_user ?? 2);

        $rows = User::where('company_id', $companyId)
            ->withCount(['devices as active_devices' => fn ($q) => $q->whereNull('revoked_at')])
            ->get(['id', 'name', 'email', 'department', 'avatar'])
            ->map(fn ($u) => [
                'id'             => $u->id,
                'name'           => $u->name,
                'email'          => $u->email,
                'department'     => $u->department,
                'avatar'        => $u->avatar,
                'active_devices' => $u->active_devices ?? 0,
                'max'            => $max,
            ]);

        return response()->json(['data' => $rows, 'max' => $max]);
    }

    // ── GET /devices/user/{userId} — one staff member's devices (managers) ──
    public function userDevices(int $userId): JsonResponse
    {
        abort_unless($this->canViewStaffDevices(), 403, 'No staff permission.');
        $target = User::where('company_id', $this->me()->company_id)->findOrFail($userId);

        return response()->json([
            'user'    => ['id' => $target->id, 'name' => $target->name],
            'max'     => (int) ($this->me()->company->max_devices_per_user ?? 2),
            'active'  => UserDevice::where('user_id', $target->id)->active()->count(),
            'devices' => $this->deviceList($target->id),
        ]);
    }

    // ── POST /devices/login-challenge — start a QR/PIN challenge ────────────
    public function createChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'nullable|integer']);

        $target = $this->me();
        if (!empty($data['user_id']) && (int) $data['user_id'] !== $this->me()->id) {
            abort_unless($this->canManageStaffDevices(), 403, 'You can only link your own device.');
            $target = User::where('company_id', $this->me()->company_id)->findOrFail($data['user_id']);
        }

        // Retire any still-open challenges for this user.
        DeviceLoginToken::where('user_id', $target->id)->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $challenge = DeviceLoginToken::issue($target, $this->me()->id, $request->ip(), $request->userAgent());

        return response()->json([
            'id'         => $challenge->id,
            'token'      => $challenge->token,
            'pin'        => $challenge->pin,
            'qr_payload' => $challenge->qrPayload(),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'ttl'        => DeviceLoginToken::TTL_SECONDS,
            'for_user'   => ['id' => $target->id, 'name' => $target->name],
        ], 201);
    }

    // ── GET /devices/login-challenge/{id} — poll status (socket fallback) ──
    public function challengeStatus(int $id): JsonResponse
    {
        $challenge = DeviceLoginToken::where('id', $id)
            ->where(fn ($q) => $q->where('created_by', $this->me()->id)->orWhere('user_id', $this->me()->id))
            ->firstOrFail();

        if ($challenge->status === 'pending' && $challenge->expires_at->isPast()) {
            $challenge->update(['status' => 'expired']);
        }

        return response()->json([
            'status'  => $challenge->status,
            'device'  => $challenge->claimed_device_id
                ? UserDevice::find($challenge->claimed_device_id)?->only(['id', 'device_name', 'platform'])
                : null,
        ]);
    }

    public function cancelChallenge(int $id): JsonResponse
    {
        DeviceLoginToken::where('id', $id)->where('created_by', $this->me()->id)
            ->where('status', 'pending')->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Cancelled.']);
    }

    // ── DELETE /devices/{id} — revoke a linked device ──────────────────────
    public function destroy(int $id): JsonResponse
    {
        $device = UserDevice::where('company_id', $this->me()->company_id)->findOrFail($id);

        if ($device->user_id !== $this->me()->id && !$this->canManageStaffDevices()) {
            abort(403, 'You can only remove your own devices.');
        }

        if ($device->isActive()) {
            $device->revoke($this->me()->id);
            broadcast(new DeviceRevoked($device->user_id, $device->id, $device->device_uid));
        }

        return response()->json(['message' => 'Device removed.']);
    }
}
