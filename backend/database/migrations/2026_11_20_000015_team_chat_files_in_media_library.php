<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team chat photos, videos, voice notes and files are stored IN the Media Library (system folder "Team chat"), so they count against the
 * one storage limit and show up in the library's usage. media_library.source says where a file came from; source_id is the chat message.
 * Files sent before this change are registered in the library too (same file, no copy), so the storage figure is correct from day one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_folders', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_id')->nullable()->after('company_id')->index();      // sub-folder of …
            $t->unsignedBigInteger('team_chat_id')->nullable()->after('parent_id')->index();    // the conversation this folder holds the files of
        });
        Schema::table('media_library', function (Blueprint $t) {
            $t->string('source', 20)->default('upload')->after('uploaded_by');      // upload | team_chat
            $t->unsignedBigInteger('source_id')->nullable()->after('source');       // team_chat_messages.id
            $t->index(['company_id', 'source']);
            $t->index(['source', 'source_id']);
        });

        if (!Schema::hasTable('team_chat_messages')) {
            return;
        }
        DB::table('team_chat_messages')->whereNotNull('attachment_url')->whereNull('deleted_at')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $m) {
                // …/storage/team-chat/{company}/file.ext  →  team-chat/{company}/file.ext on the public disk
                $path = preg_match('#/storage/(team-chat/.+)$#', (string) $m->attachment_url, $x) ? urldecode($x[1]) : null;
                if (!$path || DB::table('media_library')->where('path', $path)->exists()) { continue; }

                $company = (int) $m->company_id;
                $mime = (string) $m->attachment_mime;
                $folderId = \App\Modules\TeamChat\Support\TeamChatFiles::folderFor($company, (int) $m->chat_id)->id;
                DB::table('media_library')->insert([
                    'company_id' => $company, 'folder' => 'team-chat-' . (int) $m->chat_id, 'folder_id' => $folderId, 'filename' => basename($path),
                    'original_name' => $m->attachment_name ?: basename($path), 'display_name' => $m->attachment_name ?: basename($path),
                    'url' => $m->attachment_url, 'disk' => 'public', 'path' => $path, 'size' => (int) $m->attachment_size, 'mime_type' => $mime ?: null,
                    'uploaded_by' => $m->sender_id, 'source' => 'team_chat', 'source_id' => $m->id, 'created_at' => $m->created_at, 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('media_folders', function (Blueprint $t) {
            $t->dropColumn(['parent_id', 'team_chat_id']);
        });
        Schema::table('media_library', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'source']);
            $t->dropIndex(['source', 'source_id']);
            $t->dropColumn(['source', 'source_id']);
        });
    }
};
