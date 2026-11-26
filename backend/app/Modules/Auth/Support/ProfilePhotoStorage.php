<?php

namespace App\Modules\Auth\Support;

use App\Models\User;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use App\Support\NodeFileStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile photos live in the Media Library, in a "Profile Photos" folder (created on the first upload), hosted on the Node file host like
 * every other library file — so they can be browsed, renamed and deleted there. users.avatar holds the file's URL.
 * Replacing a photo removes the previous one (library row + file, or the old local file from before this existed).
 */
class ProfilePhotoStorage
{
    public const SLUG        = 'profile_photos';
    public const NODE_FOLDER = 'profile-photos';

    public function store(User $user, UploadedFile $file): string
    {
        $companyId = (int) $user->company_id;
        $folder = MediaFolder::firstOrCreate(
            ['company_id' => $companyId, 'slug' => self::SLUG],
            ['name' => 'Profile Photos', 'permissions' => null, 'is_system' => true, 'created_by' => null],
        );

        $ext   = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $name  = (Str::slug($user->name ?: 'staff', '_') ?: 'staff') . '_' . $user->id . '_' . now()->format('Ymd_His') . '.' . $ext;
        $bytes = (string) file_get_contents($file->getRealPath());
        $stored = NodeFileStore::store($bytes, $companyId, self::NODE_FOLDER, $name, $file->getMimeType() ?: 'image/jpeg');

        MediaLibrary::create([
            'company_id'    => $companyId,
            'folder'        => $folder->slug,
            'folder_id'     => $folder->id,
            'filename'      => basename($stored['path']) ?: $name,
            'original_name' => $name,
            'display_name'  => ($user->name ?: 'Staff') . ' — profile photo',
            'url'           => $stored['url'],
            'disk'          => $stored['disk'],
            'path'          => $stored['path'],
            'size'          => strlen($bytes),
            'mime_type'     => $file->getMimeType(),
            'uploaded_by'   => $user->id,
        ]);

        $this->forget($user);   // the previous photo, now replaced

        return $stored['url'];
    }

    /** Remove the user's current photo — its library row (and file) if it is one, or the legacy local file. */
    private function forget(User $user): void
    {
        $old = $user->avatar;
        if (!$old) { return; }

        $row = MediaLibrary::where('company_id', $user->company_id)->where('url', $old)->first();
        if ($row) {
            try { $row->deleteStoredFile(); } catch (\Throwable) { /* the hosted copy may linger; the library row is what matters */ }
            $row->delete();
        } elseif (!str_starts_with($old, 'http') && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }
    }
}
