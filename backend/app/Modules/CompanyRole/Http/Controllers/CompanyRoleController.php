<?php
use App\Http\Controllers\Controller;
use App\Models\CompanyRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyRoleController extends Controller
{
    public function index(): JsonResponse {
        return response()->json(['roles' => CompanyRole::where('company_id', auth()->user()->company_id)->get()]);
    }
    public function store(Request $request): JsonResponse {
        $d = $request->validate(['label'=>'required|string','permissions'=>'required|array']);
        $role = CompanyRole::create([
            'company_id'  => auth()->user()->company_id,
            'name'        => Str::slug($d['label'], '_'),
            'label'       => $d['label'],
            'permissions' => json_encode($d['permissions']),
            'is_system'   => false,
        ]);
        return response()->json(['role' => $role], 201);
    }
    public function update(Request $request, int $id): JsonResponse {
        $role = CompanyRole::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $d = $request->validate(['label'=>'sometimes|string','permissions'=>'sometimes|array']);
        if ($role->is_system && isset($d['permissions'])) {
            // System roles can have permissions edited but not deleted
        }
        $role->update(['label' => $d['label'] ?? $role->label, 'permissions' => json_encode($d['permissions'] ?? json_decode($role->permissions))]);
        return response()->json(['role' => $role->fresh()]);
    }
    public function destroy(int $id): JsonResponse {
        $role = CompanyRole::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        if ($role->is_system) return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        $role->delete();
        return response()->json(['message' => 'Role deleted.']);
    }
}
