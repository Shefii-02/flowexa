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
    /** 1 = top level … 4 = three folders deep. (WA Chat → Campaigns → Diwali → Banners is 4.) */
    public const MAX_DEPTH = 4;

    private static array $VALID_ROLES = ['owner', 'admin', 'team_lead', 'counsellor', 'viewer'];

    /**
     * Folder create / edit / delete is an admin capability. Role names are
     * stored capitalised ("Admin", "Manager") and superadmin is platform-level,
     * so match case-insensitively.
     */
    private function canManageFolders(?\App\Models\User $user): bool
    {
        return in_array(
            strtolower((string) ($user?->role?->name ?? '')),
            ['owner', 'admin', 'manager', 'superadmin'],
            true,
        );
    }

    /** List folders the current user can access (system + permitted custom). */
    public function index(): JsonResponse
    {
        $user    = auth()->user();
        // companies that use WA Chat get its folder tree (Campaigns, Templates, Chat files, Documents) without having to create it
        if (\App\Support\Entitlements::has($user->company, 'wa_chat')) {
            \App\Modules\WaChat\Services\WaChatMediaFolders::ensureTree((int) $user->company_id);
        }
        MediaFolder::forgetCache();
        $folders = MediaFolder::where('company_id', $user->company_id)
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get()
            ->filter(fn($f) => $f->canAccess($user))
            ->values();

        // Attach file counts — one query for all folders (there can be many now that folders nest)
        $counts  = MediaLibrary::where('company_id', $user->company_id)->whereNotNull('folder_id')->selectRaw('folder_id, count(*) c')->groupBy('folder_id')->pluck('c', 'folder_id');
        $folders = $folders->map(function ($folder) use ($counts) {
            $folder->file_count = (int) ($counts[$folder->id] ?? 0);
            return $folder;
        });

        return response()->json(['data' => $folders]);
    }

    /** Create a custom folder (owner/admin only). */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        // if (!$this->canManageFolders($user)) {
        //     return response()->json(['message' => 'Only owners and admins can create folders.'], 403);
        // }

        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'parent_id'   => 'nullable|integer',                     // a folder inside another folder
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:owner,admin,team_lead,counsellor,viewer',
        ]);

        $parent = null;
        if (!empty($validated['parent_id'])) {
            MediaFolder::forgetCache();
            $parent = MediaFolder::where('company_id', $user->company_id)->find($validated['parent_id']);
            if (!$parent || !$parent->canAccess($user)) {
                return response()->json(['message' => 'That parent folder was not found.'], 404);
            }
            if ($parent->team_chat_id || $parent->slug === 'team-chat') {
                return response()->json(['message' => 'Team chat folders are managed automatically — you cannot add folders inside them.'], 422);
            }
            if ($parent->depth() >= self::MAX_DEPTH) {
                return response()->json(['message' => 'Folders can be nested up to ' . self::MAX_DEPTH . ' levels deep.'], 422);
            }
            // a name can appear only once inside the same folder
            if (MediaFolder::where('company_id', $user->company_id)->where('parent_id', $parent->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])->exists()) {
                return response()->json(['message' => 'There is already a folder with that name here.'], 422);
            }
        }

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
            'parent_id'   => $parent?->id,
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

        // if (!$this->canManageFolders($user)) {
        //     return response()->json(['message' => 'Only owners and admins can edit folders.'], 403);
        // }

        $folder = MediaFolder::where('company_id', $user->company_id)->findOrFail($id);

        if ($folder->is_system) {
            return response()->json(['message' => 'System folders cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|in:owner,admin,team_lead,counsellor,viewer',
            'parent_id'     => 'nullable|integer',     // move this folder inside another one (null = to the top level)
        ]);

        if ($request->has('parent_id') && (int) $request->input('parent_id') !== (int) $folder->parent_id) {
            if ($error = $this->moveError($user, $folder, $request->filled('parent_id') ? $request->integer('parent_id') : null, $validated['name'] ?? $folder->name)) {
                return response()->json(['message' => $error[1]], $error[0]);
            }
            $folder->parent_id = $request->filled('parent_id') ? $request->integer('parent_id') : null;
        } elseif (isset($validated['name']) && MediaFolder::where('company_id', $user->company_id)->where('parent_id', $folder->parent_id)->where('id', '!=', $folder->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])->exists() && $folder->parent_id) {
            return response()->json(['message' => 'There is already a folder with that name here.'], 422);
        }

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

        // if (!$this->canManageFolders($user)) {
        //     return response()->json(['message' => 'Only owners and admins can delete folders.'], 403);
        // }

        $folder = MediaFolder::where('company_id', $user->company_id)->findOrFail($id);

        if ($folder->is_system) {
            return response()->json(['message' => 'System folders cannot be deleted.'], 422);
        }
        if (MediaFolder::where('company_id', $user->company_id)->where('parent_id', $folder->id)->exists()) {
            return response()->json(['message' => 'This folder has folders inside it. Move or delete those first.'], 422);
        }

        // Detach files — they become uncategorised (folder_id = null)
        MediaLibrary::where('folder_id', $folder->id)->update(['folder_id' => null]);

        $folder->delete();

        return response()->json(['message' => 'Folder deleted. Files moved to uncategorised.']);
    }

    /** Why this folder cannot be moved under $parentId — [status, message] — or null when it can. */
    private function moveError($user, MediaFolder $folder, ?int $parentId, string $name): ?array
    {
        MediaFolder::forgetCache();
        if ($parentId === null) {
            return MediaFolder::where('company_id', $user->company_id)->whereNull('parent_id')->where('id', '!=', $folder->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->exists()
                ? [422, 'There is already a folder with that name at the top level.'] : null;
        }
        $parent = MediaFolder::where('company_id', $user->company_id)->find($parentId);
        if (!$parent || !$parent->canAccess($user)) { return [404, 'That folder was not found.']; }
        if ($parent->team_chat_id || $parent->slug === 'team-chat') { return [422, 'Team chat folders are managed automatically — you cannot move folders into them.']; }
        // not into itself or into anything inside itself
        for ($p = $parent, $n = 0; $p && $n < 20; $p = $p->parent_id ? MediaFolder::where('company_id', $user->company_id)->find($p->parent_id) : null, $n++) {
            if ($p->id === $folder->id) { return [422, 'A folder cannot be moved into itself or into a folder inside it.']; }
        }
        // the folder and everything inside it must still fit within the depth limit
        if ($parent->depth() + $this->subtreeHeight($folder) > self::MAX_DEPTH) {
            return [422, 'Folders can be nested up to ' . self::MAX_DEPTH . ' levels deep.'];
        }
        if (MediaFolder::where('company_id', $user->company_id)->where('parent_id', $parent->id)->where('id', '!=', $folder->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->exists()) {
            return [422, 'There is already a folder with that name there.'];
        }
        return null;
    }

    /** How many levels this folder spans: 1 for a folder with nothing inside it. */
    private function subtreeHeight(MediaFolder $folder, int $guard = 0): int
    {
        if ($guard > 10) { return 1; }
        $kids = MediaFolder::where('company_id', $folder->company_id)->where('parent_id', $folder->id)->get();
        return 1 + ($kids->isEmpty() ? 0 : $kids->map(fn ($k) => $this->subtreeHeight($k, $guard + 1))->max());
    }
}
