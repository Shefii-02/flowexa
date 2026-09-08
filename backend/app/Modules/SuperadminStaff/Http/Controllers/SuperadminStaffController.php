<?php
namespace App\Modules\SuperadminStaff\Http\Controllers;

use App\Events\DeviceRevoked;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DeviceLoginToken;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuperadminStaffController extends Controller
{
    // GET /superadmin/staff
    public function index(Request $request): JsonResponse
    {
        $staff = User::with('role')
            ->whereHas('role', fn($q) => $q->whereIn('name', ['superadmin','superadmin_staff']))
            ->when($request->search, fn($q) => $q->where('name','like',"%{$request->search}%")->orWhere('email','like',"%{$request->search}%"))
            ->paginate(20);
        return response()->json($staff);
    }

    // POST /superadmin/staff
    public function store(Request $request): JsonResponse
    {

        $d = $request->validate([
            'name'     => ['required','string','max:100'],
            'email'    => ['required','email','unique:users,email'],
            'password' => ['required','string','min:8'],
            'role'     => ['required','in:superadmin_staff'],
        ]);
        $role = Role::where('name','superadmin_staff')->firstOrFail();
        // Platform company (where superadmin lives)
        $platformCompany = Company::where('slug','platform')->first();
        $user = User::create([
            'company_id' => $platformCompany?->id,
            'role_id'    => $role->id,
            'name'       => $d['name'],
            'email'      => $d['email'],
            'password'   => Hash::make($d['password']),
            'is_active'  => true,
        ]);
        return response()->json(['message' => 'Staff created.', 'staff' => $user->load('role')], 201);
    }

    // PUT /superadmin/staff/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $d    = $request->validate(['name' => ['sometimes','string','max:100'], 'is_active' => ['sometimes','boolean']]);
        if (!empty($request->password)) $d['password'] = Hash::make($request->password);
        $user->update($d);
        return response()->json(['message' => 'Updated.', 'staff' => $user->fresh('role')]);
    }

    // DELETE /superadmin/staff/{id}
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->role?->name === 'superadmin') return response()->json(['message' => 'Cannot delete superadmin.'], 403);
        $user->delete();
        return response()->json(['message' => 'Staff removed.']);
    }

    // PATCH /superadmin/staff/{id}/toggle
    public function toggle(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => !$user->is_active]);
        return response()->json(['message' => $user->is_active ? 'Activated.' : 'Deactivated.']);
    }

    // ═══ Phone-app linked devices (same QR / PIN feature as company staff) ═══

    /** Only users that are actually platform staff can be device-managed here. */
    private function platformStaff(int $id): User
    {
        return User::with('company')
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['superadmin', 'superadmin_staff']))
            ->findOrFail($id);
    }

    private function deviceRows(int $userId): array
    {
        return UserDevice::where('user_id', $userId)
            ->orderByDesc('last_active_at')->orderByDesc('id')->get()
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

    // GET /superadmin/staff/{id}/devices
    public function devices(int $id): JsonResponse
    {
        $staff = $this->platformStaff($id);

        return response()->json([
            'user'    => ['id' => $staff->id, 'name' => $staff->name, 'email' => $staff->email],
            'max'     => (int) ($staff->company?->max_devices_per_user ?? 2),
            'active'  => UserDevice::where('user_id', $staff->id)->active()->count(),
            'devices' => $this->deviceRows($staff->id),
        ]);
    }

    // POST /superadmin/staff/{id}/device-challenge — issue a QR / PIN login code
    public function deviceChallenge(Request $request, int $id): JsonResponse
    {
        $staff = $this->platformStaff($id);

        DeviceLoginToken::where('user_id', $staff->id)->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $challenge = DeviceLoginToken::issue($staff, $request->user()->id, $request->ip(), $request->userAgent());

        return response()->json([
            'id'         => $challenge->id,
            'token'      => $challenge->token,
            'pin'        => $challenge->pin,
            'qr_payload' => $challenge->qrPayload(),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'ttl'        => DeviceLoginToken::TTL_SECONDS,
            'for_user'   => ['id' => $staff->id, 'name' => $staff->name],
        ], 201);
    }

    // DELETE /superadmin/staff/{id}/devices/{deviceId}
    public function revokeDevice(Request $request, int $id, int $deviceId): JsonResponse
    {
        $staff  = $this->platformStaff($id);
        $device = UserDevice::where('user_id', $staff->id)->findOrFail($deviceId);

        if ($device->isActive()) {
            $device->revoke($request->user()->id);
            broadcast(new DeviceRevoked($device->user_id, $device->id, $device->device_uid));
        }

        return response()->json(['message' => 'Device removed.']);
    }
}
