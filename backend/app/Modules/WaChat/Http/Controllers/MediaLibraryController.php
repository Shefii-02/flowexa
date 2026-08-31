<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaLibraryController extends Controller
{
    private function folderFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/'))  return 'images';
        if (str_starts_with($mime, 'video/'))  return 'videos';
        if (str_starts_with($mime, 'audio/'))  return 'audio';
        return 'documents';
    }

    public function index(): JsonResponse
    {
        $files = MediaLibrary::where('company_id', auth()->user()->company_id)
            ->orderBy('created_at', 'desc')->get()
            ->groupBy('folder');
        return response()->json(['data' => $files]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:102400']); // 100MB max
        $company   = auth()->user()->company;
        $companyId = auth()->user()->company_id;

        $file      = $request->file('file');
        $sizeBytes = $file->getSize();
        $sizeMb    = $sizeBytes / 1048576;

        // Check storage limit
        $usedMb  = (float) ($company->waha_media_used_mb ?? 0);
        $limitMb = (int)   ($company->waha_media_limit_mb ?? 500);
        if ($usedMb + $sizeMb > $limitMb) {
            return response()->json(['message' => "Storage limit ({$limitMb} MB) exceeded."], 422);
        }

        $mime       = $file->getMimeType();
        $folder     = $this->folderFromMime($mime);
        $filename   = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path       = "media/{$companyId}/{$folder}/{$filename}";

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
        $url = Storage::disk('public')->url($path);

        $media = MediaLibrary::create([
            'company_id'    => $companyId,
            'folder'        => $folder,
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'display_name'  => $file->getClientOriginalName(),
            'url'           => $url,
            'disk'          => 'public',
            'path'          => $path,
            'size'          => $sizeBytes,
            'mime_type'     => $mime,
            'uploaded_by'   => auth()->id(),
        ]);

        // Update company used storage
        $company->increment('waha_media_used_mb', round($sizeMb, 4));

        return response()->json(['message' => 'Uploaded.', 'data' => $media], 201);
    }

    public function rename(Request $request, int $id): JsonResponse
    {
        $media = MediaLibrary::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $media->update(['display_name' => $request->validate(['name' => 'required|string|max:255'])['name']]);
        return response()->json(['message' => 'Renamed.', 'data' => $media]);
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
