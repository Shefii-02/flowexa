<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaLibraryController extends Controller
{
    /** Largest single file we accept, MB. */
    private const MAX_FILE_MB = 100;

    /**
     * Recompute `waha_media_used_mb` from the real sum of stored file sizes so
     * the usage figure never drifts from failed ops or rounding. Returns the
     * fresh used/limit pair (MB).
     */
    private function recalcStorage(Company $company): array
    {
        $usedBytes = (int) MediaLibrary::where('company_id', $company->id)->sum('size');
        $usedMb    = round($usedBytes / 1048576, 2);
        $company->forceFill(['waha_media_used_mb' => $usedMb])->saveQuietly();

        return ['used_mb' => $usedMb, 'limit_mb' => (int) ($company->waha_media_limit_mb ?? 500)];
    }

    private function systemFolderFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/'))  return 'images';
        if (str_starts_with($mime, 'video/'))  return 'videos';
        if (str_starts_with($mime, 'audio/'))  return 'audio';
        return 'documents';
    }

    /**
     * Resolve or lazily-create a system MediaFolder row for the auto-detected type.
     * System folders are never deleted via the API, so we ensure they exist on first use.
     */
    private function ensureSystemFolder(int $companyId, string $slug): MediaFolder
    {
        return MediaFolder::firstOrCreate(
            ['company_id' => $companyId, 'slug' => $slug],
            [
                'name'        => ucfirst($slug),
                'permissions' => null,   // open to all roles
                'is_system'   => true,
                'created_by'  => null,
            ]
        );
    }

    /** List files, grouped by folder, respecting folder permissions. */
    public function index(Request $request): JsonResponse
    {
        $user      = auth()->user();
        $companyId = $user->company_id;

        // Fetch all folders the user may access
        $accessibleFolderIds = MediaFolder::where('company_id', $companyId)
            ->get()
            ->filter(fn($f) => $f->canAccess($user))
            ->pluck('id')
            ->toArray();

        $query = MediaLibrary::where('company_id', $companyId)
            ->with(['folder', 'uploader:id,name'])
            ->orderBy('created_at', 'desc');

        // Filter by folder if requested
        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->integer('folder_id'));
        } else {
            // Respect permissions: only show files in accessible folders (or uncategorised)
            $query->where(function ($q) use ($accessibleFolderIds) {
                $q->whereNull('folder_id')
                  ->orWhereIn('folder_id', $accessibleFolderIds);
            });
        }

        $files = $query->get();

        return response()->json([
            'data'    => $files,
            'storage' => $this->recalcStorage($user->company),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'      => 'required|file|max:' . (self::MAX_FILE_MB * 1024),
            'folder_id' => 'nullable|integer',
        ]);

        $user      = auth()->user();
        $company   = $user->company;
        $companyId = $user->company_id;

        $file      = $request->file('file');
        $sizeBytes = $file->getSize();
        $sizeMb    = $sizeBytes / 1048576;

        if ($sizeMb > self::MAX_FILE_MB) {
            return response()->json(['message' => 'File is larger than the ' . self::MAX_FILE_MB . ' MB per-file limit.'], 422);
        }

        // Storage limit check against the freshly-recomputed usage.
        $storage = $this->recalcStorage($company);
        if ($storage['used_mb'] + $sizeMb > $storage['limit_mb']) {
            $remaining = max(0, round($storage['limit_mb'] - $storage['used_mb'], 1));
            return response()->json([
                'message' => "Storage limit ({$storage['limit_mb']} MB) reached — only {$remaining} MB free.",
            ], 422);
        }

        $mime   = $file->getMimeType();
        $slug   = $this->systemFolderFromMime($mime);

        // Resolve target folder
        $targetFolder = null;
        if ($request->filled('folder_id')) {
            $targetFolder = MediaFolder::where('company_id', $companyId)
                ->find($request->integer('folder_id'));
            if ($targetFolder && !$targetFolder->canAccess($user)) {
                return response()->json(['message' => 'You do not have access to this folder.'], 403);
            }
        }

        // If no valid custom folder, use or create the system folder
        if (!$targetFolder) {
            $targetFolder = $this->ensureSystemFolder($companyId, $slug);
        }

        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path     = "media/{$companyId}/{$targetFolder->slug}/{$filename}";

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
        $url = Storage::disk('public')->url($path);

        $media = MediaLibrary::create([
            'company_id'    => $companyId,
            'folder'        => $targetFolder->slug,
            'folder_id'     => $targetFolder->id,
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'display_name'  => $file->getClientOriginalName(),
            'url'           => $url,
            'disk'          => 'public',
            'path'          => $path,
            'size'          => $sizeBytes,
            'mime_type'     => $mime,
            'uploaded_by'   => $user->id,
        ]);

        return response()->json([
            'message' => 'Uploaded.',
            'data'    => $media->load('folder:id,name,slug'),
            'url'     => $url,
            'storage' => $this->recalcStorage($company),
        ], 201);
    }

    public function rename(Request $request, int $id): JsonResponse
    {
        $media = MediaLibrary::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $media->update(['display_name' => $request->validate(['name' => 'required|string|max:255'])['name']]);
        return response()->json(['message' => 'Renamed.', 'data' => $media]);
    }

    /** Move a file to a different folder. */
    public function move(Request $request, int $id): JsonResponse
    {
        $user   = auth()->user();
        $media  = MediaLibrary::where('company_id', $user->company_id)->findOrFail($id);

        $validated = $request->validate(['folder_id' => 'nullable|integer']);

        $targetFolder = null;
        if (!empty($validated['folder_id'])) {
            $targetFolder = MediaFolder::where('company_id', $user->company_id)
                ->findOrFail($validated['folder_id']);
            if (!$targetFolder->canAccess($user)) {
                return response()->json(['message' => 'You do not have access to this folder.'], 403);
            }
        }

        $media->update([
            'folder_id' => $targetFolder?->id,
            'folder'    => $targetFolder?->slug ?? $this->systemFolderFromMime((string) $media->mime_type),
        ]);

        return response()->json(['message' => 'Moved.', 'data' => $media->load('folder:id,name,slug')]);
    }

    public function copy(Request $request, int $id): JsonResponse
    {
        $user      = auth()->user();
        $media     = MediaLibrary::where('company_id', $user->company_id)->findOrFail($id);
        $company   = $user->company;
        $sizeMb    = $media->size / 1048576;

        $storage = $this->recalcStorage($company);
        if ($storage['used_mb'] + $sizeMb > $storage['limit_mb']) {
            return response()->json(['message' => "Storage limit ({$storage['limit_mb']} MB) reached — cannot copy."], 422);
        }

        $targetFolderId = $media->folder_id;
        if ($request->filled('folder_id')) {
            $targetFolder = MediaFolder::where('company_id', $user->company_id)
                ->findOrFail($request->integer('folder_id'));
            if (!$targetFolder->canAccess($user)) {
                return response()->json(['message' => 'You do not have access to this folder.'], 403);
            }
            $targetFolderId = $targetFolder->id;
        }

        $folder  = $targetFolderId ? MediaFolder::find($targetFolderId) : null;
        $slug    = $folder?->slug ?? $media->folder ?? 'documents';
        $ext     = pathinfo($media->filename, PATHINFO_EXTENSION);
        $newName = Str::uuid() . ($ext ? ".{$ext}" : '');
        $newPath = "media/{$user->company_id}/{$slug}/{$newName}";

        Storage::disk('public')->copy($media->path, $newPath);

        $copy = MediaLibrary::create([
            'company_id'    => $user->company_id,
            'folder'        => $slug,
            'folder_id'     => $targetFolderId,
            'filename'      => $newName,
            'original_name' => $media->original_name,
            'display_name'  => 'Copy of ' . $media->display_name,
            'url'           => Storage::disk('public')->url($newPath),
            'disk'          => 'public',
            'path'          => $newPath,
            'size'          => $media->size,
            'mime_type'     => $media->mime_type,
            'uploaded_by'   => $user->id,
        ]);

        return response()->json([
            'message' => 'Copied.',
            'data'    => $copy->load('folder:id,name,slug'),
            'storage' => $this->recalcStorage($company),
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $user  = auth()->user();
        $media = MediaLibrary::where('company_id', $user->company_id)->findOrFail($id);
        Storage::disk('public')->delete($media->path);
        $media->delete();
        return response()->json([
            'message' => 'Deleted.',
            'storage' => $this->recalcStorage($user->company),
        ]);
    }

    /** Resolve a target folder from the request, enforcing access. Returns [folder|null, errorResponse|null]. */
    private function resolveTargetFolder(Request $request, \App\Models\User $user): array
    {
        if (!$request->filled('folder_id')) {
            return [null, null];
        }
        $folder = MediaFolder::where('company_id', $user->company_id)
            ->find($request->integer('folder_id'));
        if (!$folder) {
            return [null, response()->json(['message' => 'Folder not found.'], 404)];
        }
        if (!$folder->canAccess($user)) {
            return [null, response()->json(['message' => 'You do not have access to this folder.'], 403)];
        }
        return [$folder, null];
    }

    /** Move many files to a folder in one call. */
    public function bulkMove(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer',
            'folder_id' => 'nullable|integer',
        ]);

        [$targetFolder, $error] = $this->resolveTargetFolder($request, $user);
        if ($error) return $error;

        $files = MediaLibrary::where('company_id', $user->company_id)
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($files as $media) {
            $media->update([
                'folder_id' => $targetFolder?->id,
                'folder'    => $targetFolder?->slug ?? $this->systemFolderFromMime((string) $media->mime_type),
            ]);
        }

        return response()->json(['message' => $files->count() . ' file(s) moved.']);
    }

    /** Copy many files to a folder in one call (defaults to each file's own folder). */
    public function bulkCopy(Request $request): JsonResponse
    {
        $user    = auth()->user();
        $company = $user->company;

        $validated = $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer',
            'folder_id' => 'nullable|integer',
        ]);

        [$targetFolder, $error] = $this->resolveTargetFolder($request, $user);
        if ($error) return $error;

        $files = MediaLibrary::where('company_id', $user->company_id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $totalMb = $files->sum('size') / 1048576;
        $storage = $this->recalcStorage($company);
        if ($storage['used_mb'] + $totalMb > $storage['limit_mb']) {
            return response()->json(['message' => "Storage limit ({$storage['limit_mb']} MB) reached — cannot copy."], 422);
        }

        foreach ($files as $media) {
            $folderId = $targetFolder ? $targetFolder->id : $media->folder_id;
            $folder   = $folderId ? MediaFolder::find($folderId) : null;
            $slug     = $folder?->slug ?? $media->folder ?? 'documents';
            $ext      = pathinfo($media->filename, PATHINFO_EXTENSION);
            $newName  = Str::uuid() . ($ext ? ".{$ext}" : '');
            $newPath  = "media/{$user->company_id}/{$slug}/{$newName}";

            Storage::disk('public')->copy($media->path, $newPath);

            MediaLibrary::create([
                'company_id'    => $user->company_id,
                'folder'        => $slug,
                'folder_id'     => $folderId,
                'filename'      => $newName,
                'original_name' => $media->original_name,
                'display_name'  => 'Copy of ' . $media->display_name,
                'url'           => Storage::disk('public')->url($newPath),
                'disk'          => 'public',
                'path'          => $newPath,
                'size'          => $media->size,
                'mime_type'     => $media->mime_type,
                'uploaded_by'   => $user->id,
            ]);
        }

        return response()->json([
            'message' => $files->count() . ' file(s) copied.',
            'storage' => $this->recalcStorage($company),
        ], 201);
    }

    /** Delete many files in one call. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $files = MediaLibrary::where('company_id', $user->company_id)
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($files as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        return response()->json([
            'message' => $files->count() . ' file(s) deleted.',
            'storage' => $this->recalcStorage($user->company),
        ]);
    }
}
