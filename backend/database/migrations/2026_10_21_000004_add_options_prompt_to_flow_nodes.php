<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $t) {
            // When set, a list / button node sends its message first, then the options as a SEPARATE message whose
            // text is this prompt ("Please choose from any of the options below 👇").
            $t->string('options_prompt', 1024)->nullable()->after('option_description');
            // Adds "Type M for Main Menu / Type E to Exit Chat" under the options. Off for nodes that already exist.
            $t->boolean('show_shortcuts')->default(false)->after('options_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', fn (Blueprint $t) => $t->dropColumn(['options_prompt', 'show_shortcuts']));
    }
};
