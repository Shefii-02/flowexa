<?php

namespace App\Modules\TeamChat\Support;

use App\Models\Company;
use App\Modules\WaChat\Models\MediaFolder;
use App\Modules\WaChat\Models\MediaLibrary;
use App\Support\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Team chat attachments live in the Media Library (system folder "Team chat") so they share its storage limit and usage figures. */
class TeamChatFiles
{
    public const FOLDER = 'team-chat';

    /** "Team chat" root folder, then one sub-folder per conversation (named after the group, or "A & B" for a 1:1) — created on first use. */
    public static function folderFor(int $companyId, int $chatId): MediaFolder
    {
        $root = MediaFolder::firstOrCreate(['company_id' => $companyId, 'slug' => self::FOLDER], ['name' => 'Team chat', 'permissions' => null, 'is_system' => true, 'created_by' => null]);
        $name = self::chatName($chatId);
        $sub = MediaFolder::firstOrCreate(
            ['company_id' => $companyId, 'slug' => self::FOLDER . '-' . $chatId],
            ['name' => $name, 'parent_id' => $root->id, 'team_chat_id' => $chatId, 'permissions' => null, 'is_system' => true, 'created_by' => null],
        );
        if ($sub->name !== $name || (int) $sub->parent_id !== (int) $root->id) { $sub->forceFill(['name' => $name, 'parent_id' => $root->id])->save(); }   // follows a group rename
        return $sub;
    }

    private static function chatName(int $chatId): string
    {
        $c = DB::table('team_chats')->where('id', $chatId)->first(['type', 'name']);
        if ($c && $c->type === 'group' && filled($c->name)) { return mb_substr((string) $c->name, 0, 100); }
        $names = DB::table('team_chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.chat_id', $chatId)->orderBy('u.name')->pluck('u.name')->all();
        return mb_substr($names ? implode(' & ', $names) : "Chat #{$chatId}", 0, 100);
    }

    /** Store an upload in the library, inside the conversation's folder. Returns [path, url, mime, size, name]. The message links to it afterwards (see link()). */
    public static function store(Company $company, int $chatId, UploadedFile $f): array
    {
        $path = "media/{$company->id}/" . self::FOLDER . "/{$chatId}/" . Str::uuid() . '.' . ($f->getClientOriginalExtension() ?: 'bin');
        Storage::disk('public')->put($path, file_get_contents($f->getRealPath()));
        return ['path' => $path, 'url' => Storage::disk('public')->url($path), 'mime' => (string) $f->getMimeType(), 'size' => (int) $f->getSize(), 'name' => $f->getClientOriginalName()];
    }

    /** Register the stored file in the library against the message that carries it. */
    public static function link(int $companyId, int $chatId, int $messageId, int $senderId, array $file): void
    {
        $folder = self::folderFor($companyId, $chatId);
        MediaLibrary::create([
            'company_id' => $companyId, 'folder' => $folder->slug, 'folder_id' => $folder->id, 'filename' => basename($file['path']),
            'original_name' => $file['name'], 'display_name' => $file['name'], 'url' => $file['url'], 'disk' => 'public', 'path' => $file['path'],
            'size' => $file['size'], 'mime_type' => $file['mime'], 'uploaded_by' => $senderId, 'source' => 'team_chat', 'source_id' => $messageId,
        ]);
        MediaStorage::summary(Company::find($companyId));   // keeps the company's recorded usage current
    }

    /**
     * A library file that belongs to a chat message is being deleted: the message turns into a plain note, so no chat is left with a
     * broken image / player. (The file itself is removed by the caller.)
     */
    public static function released(MediaLibrary $media): void
    {
        if ($media->source !== 'team_chat' || !$media->source_id) { return; }
        DB::table('team_chat_messages')->where('id', $media->source_id)->where('company_id', $media->company_id)->update([
            'type' => 'text', 'body' => '📎 File removed to free storage', 'attachment_url' => null, 'attachment_name' => null,
            'attachment_size' => null, 'attachment_mime' => null, 'duration_seconds' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * A chat (group) is deleted. $withMedia = true: every file it shared is removed too, and its folder, freeing the storage.
     * false: the messages go but the files stay in the library (folder "Team chat / <chat>") for admins to keep or clear later.
     * Returns how many files and MB were affected.
     */
    public static function purgeChat(int $chatId, bool $withMedia): array
    {
        $ids = DB::table('team_chat_messages')->where('chat_id', $chatId)->pluck('id');
        $media = $ids->isEmpty() ? collect() : MediaLibrary::where('source', 'team_chat')->whereIn('source_id', $ids)->get();
        $company = $media->first()?->company_id ?? DB::table('team_chats')->where('id', $chatId)->value('company_id');
        $bytes = (int) $media->sum('size');

        if ($withMedia) {
            foreach ($media as $m) {
                Storage::disk($m->disk ?: 'public')->delete($m->path);
                $m->delete();
            }
            // anything else left in the conversation's folder (e.g. files an admin moved there), then the folder itself
            $folder = MediaFolder::where('team_chat_id', $chatId)->first();
            if ($folder && !MediaLibrary::where('folder_id', $folder->id)->exists()) { $folder->delete(); }
        } else {
            MediaLibrary::whereIn('id', $media->pluck('id'))->update(['source_id' => null]);       // the messages are gone; the files remain
            MediaFolder::where('team_chat_id', $chatId)->update(['team_chat_id' => null]);          // the folder stays as a plain, admin-only archive folder
        }
        if ($company) { MediaStorage::summary(Company::find($company)); }
        return ['files' => $media->count(), 'mb' => round($bytes / 1048576, 2), 'media_deleted' => $withMedia];
    }
}
