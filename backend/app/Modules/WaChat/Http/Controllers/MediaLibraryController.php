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
    /** Used / limit / free / percent (+ what the space is used by) — shared with team chat, whose files live in this library. */
    private function recalcStorage(Company $company): array
    {
        return \App\Support\MediaStorage::summary($company, true);
    }

    /** A file from a private source (team chat) may only be touched by someone who can open its folder. */
    private function guardPrivate(MediaLibrary $media, \App\Models\User $user): ?JsonResponse
    {
        if ($media->source !== 'upload' && $media->folder_id) {
            $folder = MediaFolder::find($media->folder_id);
            if ($folder && !$folder->canAccess($user)) {
                return response()->json(['message' => 'You do not have access to this file.'], 403);
            }
        }
        return null;
    }

    /**
     * A new, independent stored copy of a file (so deleting one never breaks the other). Node-hosted files are read back and re-hosted;
     * files on this server's disk are copied on the disk. Null when the original cannot be read.
     * @return array{filename:string,url:string,disk:string,path:string}|null
     */
    private function cloneFile(MediaLibrary $media, int $companyId, string $slug): ?array
    {
        $ext = pathinfo((string) $media->filename, PATHINFO_EXTENSION);
        if ($media->disk === 'node') {
            $bytes = \App\Support\NodeFileStore::read((string) $media->url);
            if ($bytes === null) { return null; }
            $s = \App\Support\NodeFileStore::store($bytes, $companyId, 'library', 'copy' . ($ext ? ".{$ext}" : ''), $media->mime_type);
            return ['filename' => basename($s['path']), 'url' => $s['url'], 'disk' => $s['disk'], 'path' => $s['path']];
        }
        $newName = Str::uuid() . ($ext ? ".{$ext}" : '');
        $newPath = "media/{$companyId}/{$slug}/{$newName}";
        if (!Storage::disk('public')->exists((string) $media->path)) { return null; }
        Storage::disk('public')->copy($media->path, $newPath);
        return ['filename' => $newName, 'url' => Storage::disk('public')->url($newPath), 'disk' => 'public', 'path' => $newPath];
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
            abort_unless(in_array($request->integer('folder_id'), $accessibleFolderIds, true) || !MediaFolder::where('company_id', $companyId)->whereKey($request->integer('folder_id'))->exists(), 403, 'You do not have access to this folder.');
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
            'purpose'   => 'nullable|in:' . implode(',', array_keys(\App\Modules\WaChat\Services\WaChatMediaFolders::PURPOSES)),   // what it is for: lands in the matching WA Chat folder
        ]);

        $user      = auth()->user();
        $company   = $user->company;
        $companyId = $user->company_id;

        $file      = $request->file('file');
        if ($err = \App\Support\Meta\MediaRules::validate($file, 'library')) {
            return response()->json(['message' => $err], 422);
        }
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

        // an upload that says what it is for goes into that WA Chat folder
        if (!$targetFolder && $request->filled('purpose')) {
            $targetFolder = \App\Modules\WaChat\Services\WaChatMediaFolders::folderFor($companyId, $request->input('purpose'));
        }
        // If no valid custom folder, use or create the system folder
        if (!$targetFolder) {
            $targetFolder = $this->ensureSystemFolder($companyId, $slug);
        }

        // hosted by the Node gateway and shared from there (this server's disk only when the gateway cannot take it)
        $stored   = \App\Support\NodeFileStore::store((string) file_get_contents($file->getRealPath()), (int) $companyId, 'library', $file->getClientOriginalName(), $mime);
        $url      = $stored['url'];
        $path     = $stored['path'];
        $filename = basename($path) ?: (Str::uuid() . '.' . $file->getClientOriginalExtension());

        $media = MediaLibrary::create([
            'company_id'    => $companyId,
            'folder'        => $targetFolder->slug,
            'folder_id'     => $targetFolder->id,
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'display_name'  => $file->getClientOriginalName(),
            'url'           => $url,
            'disk'          => $stored['disk'],
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
        if ($deny = $this->guardPrivate($media, auth()->user())) return $deny;
        $media->update(['display_name' => $request->validate(['name' => 'required|string|max:255'])['name']]);
        return response()->json(['message' => 'Renamed.', 'data' => $media]);
    }

    /** Move a file to a different folder. */
    public function move(Request $request, int $id): JsonResponse
    {
        $user   = auth()->user();
        $media  = MediaLibrary::where('company_id', $user->company_id)->findOrFail($id);
        if ($deny = $this->guardPrivate($media, $user)) return $deny;

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
        if ($deny = $this->guardPrivate($media, $user)) return $deny;
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
        $cloned = $this->cloneFile($media, (int) $user->company_id, $slug);
        if ($cloned === null) {
            return response()->json(['message' => 'Could not copy that file — its stored copy could not be read.'], 502);
        }

        $copy = MediaLibrary::create([
            'company_id'    => $user->company_id,
            'folder'        => $slug,
            'folder_id'     => $targetFolderId,
            'filename'      => $cloned['filename'],
            'original_name' => $media->original_name,
            'display_name'  => 'Copy of ' . $media->display_name,
            'url'           => $cloned['url'],
            'disk'          => $cloned['disk'],
            'path'          => $cloned['path'],
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
        if ($deny = $this->guardPrivate($media, $user)) return $deny;
        \App\Modules\TeamChat\Support\TeamChatFiles::released($media);   // a chat message that carried it becomes a plain note
        $media->deleteStoredFile();
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
            ->get()
            ->reject(fn ($f) => $this->guardPrivate($f, $user) !== null);   // private (team chat) files stay out of reach

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
            ->get()
            ->reject(fn ($f) => $this->guardPrivate($f, $user) !== null);   // private (team chat) files stay out of reach

        $totalMb = $files->sum('size') / 1048576;
        $storage = $this->recalcStorage($company);
        if ($storage['used_mb'] + $totalMb > $storage['limit_mb']) {
            return response()->json(['message' => "Storage limit ({$storage['limit_mb']} MB) reached — cannot copy."], 422);
        }

        $copied = 0; $skipped = 0;
        foreach ($files as $media) {
            $folderId = $targetFolder ? $targetFolder->id : $media->folder_id;
            $folder   = $folderId ? MediaFolder::find($folderId) : null;
            $slug     = $folder?->slug ?? $media->folder ?? 'documents';
            $cloned = $this->cloneFile($media, (int) $user->company_id, $slug);
            if ($cloned === null) { $skipped++; continue; }   // its stored copy cannot be read — skip it rather than fail the whole batch

            MediaLibrary::create([
                'company_id'    => $user->company_id,
                'folder'        => $slug,
                'folder_id'     => $folderId,
                'filename'      => $cloned['filename'],
                'original_name' => $media->original_name,
                'display_name'  => 'Copy of ' . $media->display_name,
                'url'           => $cloned['url'],
                'disk'          => $cloned['disk'],
                'path'          => $cloned['path'],
                'size'          => $media->size,
                'mime_type'     => $media->mime_type,
                'uploaded_by'   => $user->id,
            ]);
            $copied++;
        }

        return response()->json([
            'message' => $copied . ' file(s) copied.' . ($skipped ? " {$skipped} could not be copied (their stored file could not be read)." : ''),
            'copied'  => $copied, 'skipped' => $skipped,
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
            ->get()
            ->reject(fn ($f) => $this->guardPrivate($f, $user) !== null);   // private (team chat) files stay out of reach

        foreach ($files as $media) {
            if ($this->guardPrivate($media, $user)) { continue; }   // private files (team chat) are skipped for people who cannot open that folder
            \App\Modules\TeamChat\Support\TeamChatFiles::released($media);
            $media->deleteStoredFile();
            $media->delete();
        }

        return response()->json([
            'message' => $files->count() . ' file(s) deleted.',
            'storage' => $this->recalcStorage($user->company),
        ]);
    }
}
