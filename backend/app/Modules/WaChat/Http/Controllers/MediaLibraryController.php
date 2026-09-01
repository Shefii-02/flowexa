<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaLibraryController extends Controller
{
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

        return response()->json(['data' => $files]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'      => 'required|file|max:102400',
            'folder_id' => 'nullable|integer',
        ]);

        $user      = auth()->user();
        $company   = $user->company;
        $companyId = $user->company_id;

        $file      = $request->file('file');
        $sizeBytes = $file->getSize();
        $sizeMb    = $sizeBytes / 1048576;

        // Storage limit check
        $usedMb  = (float) ($company->waha_media_used_mb ?? 0);
        $limitMb = (int)   ($company->waha_media_limit_mb ?? 500);
        if ($usedMb + $sizeMb > $limitMb) {
            return response()->json(['message' => "Storage limit ({$limitMb} MB) exceeded."], 422);
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
            'folder'        => $targetFolder->is_system ? $targetFolder->slug : 'documents',
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

        $company->increment('waha_media_used_mb', round($sizeMb, 4));

        return response()->json(['message' => 'Uploaded.', 'data' => $media, 'url' => $url], 201);
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

        $media->update(['folder_id' => $targetFolder?->id]);

        return response()->json(['message' => 'Moved.', 'data' => $media]);
    }

    public function destroy(int $id): JsonResponse
    {
        $media     = MediaLibrary::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $sizeMb    = $media->size / 1048576;
        Storage::disk('public')->delete($media->path);
        auth()->user()->company->decrement('waha_media_used_mb', round($sizeMb, 4));
        $media->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
