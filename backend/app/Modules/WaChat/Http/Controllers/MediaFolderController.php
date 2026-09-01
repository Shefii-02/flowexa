<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaFolderController extends Controller
{
    private static array $VALID_ROLES = ['owner', 'admin', 'team_lead', 'counsellor', 'viewer'];

    /** List folders the current user can access (system + permitted custom). */
    public function index(): JsonResponse
    {
        $user    = auth()->user();
        $folders = MediaFolder::where('company_id', $user->company_id)
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get()
            ->filter(fn($f) => $f->canAccess($user))
            ->values();

        // Attach file counts
        $folders = $folders->map(function ($folder) {
            $folder->file_count = MediaLibrary::where('folder_id', $folder->id)->count();
            return $folder;
        });

        return response()->json(['data' => $folders]);
    }

    /** Create a custom folder (owner/admin only). */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!in_array($user->role?->name, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'Only owners and admins can create folders.'], 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:owner,admin,team_lead,counsellor,viewer',
        ]);

        $slug = Str::slug($validated['name']);
        $base = $slug;
        $i    = 1;
        while (MediaFolder::where('company_id', $user->company_id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        $permissions = !empty($validated['permissions']) ? $validated['permissions'] : null;

        $folder = MediaFolder::create([
            'company_id'  => $user->company_id,
            'name'        => $validated['name'],
            'slug'        => $slug,
            'permissions' => $permissions,
            'is_system'   => false,
            'created_by'  => $user->id,
        ]);

        $folder->file_count = 0;

        return response()->json(['message' => 'Folder created.', 'data' => $folder], 201);
    }

    /** Update folder name or permissions (owner/admin only). */
    public function update(Request $request, int $id): JsonResponse
    {
        $user   = auth()->user();

        if (!in_array($user->role?->name, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'Only owners and admins can edit folders.'], 403);
        }

        $folder = MediaFolder::where('company_id', $user->company_id)->findOrFail($id);

        if ($folder->is_system) {
            return response()->json(['message' => 'System folders cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|in:owner,admin,team_lead,counsellor,viewer',
        ]);

        if (isset($validated['name'])) {
            $folder->name = $validated['name'];
        }

        if (array_key_exists('permissions', $validated)) {
            $folder->permissions = !empty($validated['permissions']) ? $validated['permissions'] : null;
        }

        $folder->save();

        return response()->json(['message' => 'Updated.', 'data' => $folder]);
    }

    /** Delete a custom folder (moves its files to null folder_id). */
    public function destroy(int $id): JsonResponse
    {
        $user   = auth()->user();

        if (!in_array($user->role?->name, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'Only owners and admins can delete folders.'], 403);
        }

        $folder = MediaFolder::where('company_id', $user->company_id)->findOrFail($id);

        if ($folder->is_system) {
            return response()->json(['message' => 'System folders cannot be deleted.'], 422);
        }

        // Detach files — they become uncategorised (folder_id = null)
        MediaLibrary::where('folder_id', $folder->id)->update(['folder_id' => null]);

        $folder->delete();

        return response()->json(['message' => 'Folder deleted. Files moved to uncategorised.']);
    }
}
