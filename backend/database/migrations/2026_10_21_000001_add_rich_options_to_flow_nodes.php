<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $t) {
            // Interactive message header: text | image | video | document. Media headers reuse media_type/media_url/media_filename.
            $t->string('header_type', 10)->nullable()->after('message');
            $t->string('header_text', 60)->nullable()->after('header_type');
            $t->string('footer_text', 60)->nullable()->after('header_text');
            // Label of the button that opens a list (default "View Options").
            $t->string('list_button_label', 20)->nullable()->after('footer_text');
            // Non-reply buttons: [{type: url|phone|copy_code, text, url|phone|code}]. Reply buttons and list rows are child nodes.
            $t->json('action_buttons')->nullable()->after('list_button_label');
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', fn (Blueprint $t) => $t->dropColumn(['header_type', 'header_text', 'footer_text', 'list_button_label', 'action_buttons']));
    }
};
