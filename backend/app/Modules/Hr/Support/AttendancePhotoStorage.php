<?php

namespace App\Modules\Hr\Support;

use App\Models\Company;
use App\Models\GoogleIntegration;
use App\Models\User;
use App\Modules\Google\GoogleClient;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an attendance selfie (clock in/out, break start/end) and returns its
 * public URL. Google Drive first, when the company has it connected — a
 * dedicated "HR Attendance" root folder with one subfolder per calendar day,
 * checked-or-created on every upload rather than cached, since a cached
 * folder id could go stale if it's renamed/trashed on the Google side.
 * Falls back to the local Media Library (its own "HR Attendance" folder,
 * physically laid out the same way: one directory per day) when Drive isn't
 * connected, and keeps `companies.hr_storage_used_mb` in sync with what that
 * local fallback is actually using — Drive storage doesn't count against it.
 */
class AttendancePhotoStorage
{
    public function __construct(private readonly GoogleClient $google) {}

    public function store(User $user, UploadedFile $file, string $action): string
    {
        $integration = GoogleIntegration::where('company_id', $user->company_id)
            ->where('is_active', true)->first();

        $day  = now()->toDateString();
        $name = $this->filename($user, $action, $file);

        if ($integration) {
            try {
                return $this->storeOnDrive($integration, $file, $name, $day);
            } catch (\Throwable) {
                // Drive hiccup (expired grant, network) — don't block a clock-in over it.
            }
        }

        return $this->storeLocally($user->company_id, $file, $name, $day);
    }

    private function filename(User $user, string $action, UploadedFile $file): string
    {
        $slug = Str::slug($user->name ?: 'staff', '_') ?: 'staff';
        $ext  = $file->getClientOriginalExtension() ?: 'jpg';
        return "{$slug}_" . now()->format('Y-m-d_His') . "_{$action}.{$ext}";
    }

    private function storeOnDrive(GoogleIntegration $integration, UploadedFile $file, string $name, string $day): string
    {
        if (!$integration->drive_hr_folder_id) {
            $root = $this->google->createFolder($integration, config('app.name') . ' — HR Attendance');
            $integration->update(['drive_hr_folder_id' => $root['id'], 'drive_hr_folder_url' => $root['url']]);
        }

        $dayFolder = $this->google->findOrCreateFolder($integration, $day, $integration->drive_hr_folder_id);

        $uploaded = $this->google->uploadFile(
            $integration,
            file_get_contents($file->getRealPath()),
            $name,
            $file->getMimeType() ?: 'image/jpeg',
            $dayFolder['id'],
        );

        return $uploaded['download_url'];
    }

    private function storeLocally(int $companyId, UploadedFile $file, string $name, string $day): string
    {
        $folder = MediaFolder::firstOrCreate(
            ['company_id' => $companyId, 'slug' => 'hr_attendance'],
            ['name' => 'HR Attendance', 'permissions' => null, 'is_system' => true, 'created_by' => null],
        );

        $path = "media/{$companyId}/hr_attendance/{$day}/" . Str::uuid() . '_' . $name;
        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
        $url = Storage::disk('public')->url($path);

        MediaLibrary::create([
            'company_id'    => $companyId,
            'folder'        => $folder->slug,
            'folder_id'     => $folder->id,
            'filename'      => basename($path),
            'original_name' => $name,
            'display_name'  => $name,
            'url'           => $url,
            'disk'          => 'public',
            'path'          => $path,
            'size'          => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'uploaded_by'   => auth()->id(),
        ]);

        $this->recalcHrStorage($companyId);

        return $url;
    }

    private function recalcHrStorage(int $companyId): void
    {
        $folderId = MediaFolder::where('company_id', $companyId)->where('slug', 'hr_attendance')->value('id');
        $usedBytes = $folderId ? (int) MediaLibrary::where('company_id', $companyId)->where('folder_id', $folderId)->sum('size') : 0;

        Company::where('id', $companyId)->first()?->forceFill([
            'hr_storage_used_mb' => round($usedBytes / 1048576, 2),
        ])->saveQuietly();
    }
}
