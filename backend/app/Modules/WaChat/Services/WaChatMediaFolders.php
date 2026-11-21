<?php

namespace App\Modules\WaChat\Services;

use App\Modules\WaChat\Models\MediaFolder;

/**
 * The folders WA Chat keeps its files in, inside the shared Media Library:
 *
 *   WA Chat
 *     ├ Campaigns             media uploaded for broadcast campaigns
 *     ├ Templates             media used by message templates
 *     ├ Chat files            files and media sent in conversations
 *     └ Documents & invoices  invoices, brochures and other documents sent from WA Chat
 *
 * They are system folders (cannot be renamed or deleted) created on first use; staff can add their own sub-folders inside any of them.
 */
class WaChatMediaFolders
{
    public const ROOT_SLUG = 'wa-chat';

    /** folder slug => label */
    public const CHILDREN = [
        'wa-chat-campaigns' => 'Campaigns',
        'wa-chat-templates' => 'Templates',
        'wa-chat-chat-files' => 'Chat files',
        'wa-chat-documents' => 'Documents & invoices',
    ];

    /** what an upload says it is for => the folder it belongs in */
    public const PURPOSES = [
        'wa_campaign' => 'wa-chat-campaigns',
        'wa_template' => 'wa-chat-templates',
        'wa_chat'     => 'wa-chat-chat-files',
        'wa_document' => 'wa-chat-documents',
    ];

    /** Create whatever is missing of the WA Chat folder tree and return the root. Cheap when it all exists (one query). */
    public static function ensureTree(int $companyId): MediaFolder
    {
        $have = MediaFolder::where('company_id', $companyId)->whereIn('slug', array_merge([self::ROOT_SLUG], array_keys(self::CHILDREN)))->get()->keyBy('slug');
        $root = $have[self::ROOT_SLUG] ?? MediaFolder::create(['company_id' => $companyId, 'name' => 'WA Chat', 'slug' => self::ROOT_SLUG, 'permissions' => null, 'is_system' => true, 'created_by' => null]);
        foreach (self::CHILDREN as $slug => $label) {
            if (!isset($have[$slug])) {
                MediaFolder::create(['company_id' => $companyId, 'parent_id' => $root->id, 'name' => $label, 'slug' => $slug, 'permissions' => null, 'is_system' => true, 'created_by' => null]);
            } elseif ((int) $have[$slug]->parent_id !== (int) $root->id) {
                $have[$slug]->forceFill(['parent_id' => $root->id])->save();
            }
        }
        return $root;
    }

    /**
     * Host a file for WA Chat AND list it in the library under the folder for its purpose, so files WA Chat uses are not invisible on the file host.
     * Returns the public URL. The record is best-effort: if it cannot be written the file is still hosted and the URL still works.
     */
    public static function hostAndRegister(string $bytes, int $companyId, ?int $userId, string $purpose, string $filename, ?string $mime = null, bool $onlyIfRoom = false): string
    {
        $stored = \App\Support\NodeFileStore::store($bytes, $companyId, 'library', $filename, $mime);
        try {
            // automatic filing (e.g. AI voice replies) must never push a company over its storage limit — the file is still hosted and sent, just not listed
            if ($onlyIfRoom && ($company = \App\Models\Company::find($companyId)) && \App\Support\MediaStorage::refusal($company, strlen($bytes)) !== null) { return $stored['url']; }
            $folder = self::folderFor($companyId, $purpose);
            \App\Modules\WaChat\Models\MediaLibrary::create([
                'company_id' => $companyId, 'folder' => $folder?->slug ?? 'documents', 'folder_id' => $folder?->id, 'filename' => basename($stored['path']) ?: $filename,
                'original_name' => $filename, 'display_name' => $filename, 'url' => $stored['url'], 'disk' => $stored['disk'], 'path' => $stored['path'],
                'size' => strlen($bytes), 'mime_type' => $mime, 'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not list a WA Chat file in the library: ' . $e->getMessage());
        }
        return $stored['url'];
    }

    /** The folder for an upload purpose (wa_campaign / wa_template / wa_chat / wa_document), or null for an unknown one. */
    public static function folderFor(int $companyId, ?string $purpose): ?MediaFolder
    {
        $slug = self::PURPOSES[$purpose ?? ''] ?? null;
        if ($slug === null) { return null; }
        self::ensureTree($companyId);
        return MediaFolder::where('company_id', $companyId)->where('slug', $slug)->first();
    }
}
