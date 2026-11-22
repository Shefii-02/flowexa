<?php

namespace App\Modules\Hr\Support;

use App\Models\Company;
use App\Models\GoogleIntegration;
use App\Models\User;
use App\Modules\Google\GoogleClient;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use App\Support\NodeFileStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stores an attendance selfie (clock in/out, break start/end) and returns its public URL.
 *
 *  1. Google Drive — when the company's plan includes Google Drive AND a Google account is connected: a dedicated
 *     "HR Attendance" root folder with one subfolder per calendar day (checked-or-created on every upload, never cached — a cached id
 *     goes stale if somebody renames or trashes the folder on the Google side). Everyone's photos for a day land in that day's folder.
 *  2. Media Library — otherwise: the same layout as real library folders, "HR Attendance" → one folder per day ("2026-11-21"),
 *     created when the first photo of the day arrives, so the photos are browsable, searchable and deletable in the library.
 *     The file itself is hosted on the Node file host (this server's disk only if that is unreachable).
 *
 * A Drive failure never blocks a clock-in: it is logged and the photo goes to the Media Library instead.
 */
class AttendancePhotoStorage
{
    public const ROOT_SLUG   = 'hr_attendance';
    public const NODE_FOLDER = 'hr-attendance';

    public function __construct(private readonly GoogleClient $google) {}

    public function store(User $user, UploadedFile $file, string $action): string
    {
        $day  = now()->toDateString();
        $name = $this->filename($user, $action, $file);

        if ($integration = $this->driveFor($user->company)) {
            try {
                return $this->storeOnDrive($integration, $file, $name, $day);
            } catch (\Throwable $e) {
                Log::warning("HR attendance photo: Google Drive upload failed for company {$user->company_id} — using the Media Library instead: " . $e->getMessage());
            }
        }

        return $this->storeInLibrary($user, $file, $name, $day);
    }

    /** The Google connection to use — only when Drive is part of the plan AND a connected account is active. */
    public function driveFor(?Company $company): ?GoogleIntegration
    {
        if (!$company) { return null; }
        $plan = $company->plan;
        if ($plan && !$plan->google_drive_enabled) { return null; }

        return GoogleIntegration::where('company_id', $company->id)->where('is_active', true)->first();
    }

    private function filename(User $user, string $action, UploadedFile $file): string
    {
        $slug = Str::slug($user->name ?: 'staff', '_') ?: 'staff';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        return "{$slug}_" . now()->format('Y-m-d_His') . "_{$action}.{$ext}";
    }

    private function storeOnDrive(GoogleIntegration $integration, UploadedFile $file, string $name, string $day): string
    {
        try {
            return $this->uploadToDayFolder($integration, $file, $name, $day);
        } catch (\Throwable $e) {
            if (!$integration->drive_hr_folder_id) { throw $e; }
            // The saved "HR Attendance" folder may have been deleted or trashed on the Google side — forget it, make a new one and try once more.
            Log::info("HR attendance photo: saved Drive folder for company {$integration->company_id} no longer works ({$e->getMessage()}) — creating a new one");
            $integration->update(['drive_hr_folder_id' => null, 'drive_hr_folder_url' => null]);
            return $this->uploadToDayFolder($integration, $file, $name, $day);
        }
    }

    private function uploadToDayFolder(GoogleIntegration $integration, UploadedFile $file, string $name, string $day): string
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

    /** "HR Attendance" → "YYYY-MM-DD" in the Media Library, created on first use. Only owners and admins can open the root (and so every day inside it). */
    public function dayFolder(int $companyId, string $day): MediaFolder
    {
        $root = MediaFolder::firstOrCreate(
            ['company_id' => $companyId, 'slug' => self::ROOT_SLUG],
            ['name' => 'HR Attendance', 'permissions' => ['owner', 'admin'], 'is_system' => true, 'created_by' => null],
        );
        // a root made before day folders existed lives at the top level — keep it there, only the days go inside it

        return MediaFolder::firstOrCreate(
            ['company_id' => $companyId, 'slug' => self::ROOT_SLUG . '_' . $day],
            ['name' => $day, 'parent_id' => $root->id, 'permissions' => null, 'is_system' => true, 'created_by' => null],
        );
    }

    private function storeInLibrary(User $user, UploadedFile $file, string $name, string $day): string
    {
        $companyId = (int) $user->company_id;
        $folder    = $this->dayFolder($companyId, $day);
        $bytes     = (string) file_get_contents($file->getRealPath());
        $stored    = NodeFileStore::store($bytes, $companyId, self::NODE_FOLDER, $name, $file->getMimeType() ?: 'image/jpeg');

        MediaLibrary::create([
            'company_id'    => $companyId,
            'folder'        => $folder->slug,
            'folder_id'     => $folder->id,
            'filename'      => basename($stored['path']) ?: $name,
            'original_name' => $name,
            'display_name'  => $name,
            'url'           => $stored['url'],
            'disk'          => $stored['disk'],
            'path'          => $stored['path'],
            'size'          => strlen($bytes),
            'mime_type'     => $file->getMimeType(),
            'uploaded_by'   => $user->id,
        ]);

        $this->recalcHrStorage($companyId);

        return $stored['url'];
    }

    /** companies.hr_storage_used_mb = what the "HR Attendance" folders (all days) hold in the library. Drive storage does not count. */
    private function recalcHrStorage(int $companyId): void
    {
        $ids = MediaFolder::where('company_id', $companyId)->where('slug', 'like', self::ROOT_SLUG . '%')->pluck('id');
        $usedBytes = $ids->isEmpty() ? 0 : (int) MediaLibrary::where('company_id', $companyId)->whereIn('folder_id', $ids)->sum('size');

        Company::where('id', $companyId)->first()?->forceFill([
            'hr_storage_used_mb' => round($usedBytes / 1048576, 2),
        ])->saveQuietly();
    }
}
