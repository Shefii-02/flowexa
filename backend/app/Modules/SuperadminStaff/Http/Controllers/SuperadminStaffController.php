<?php
namespace App\Modules\SuperadminStaff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
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
}
