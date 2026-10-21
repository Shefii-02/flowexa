<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $t) {
            // Short text shown under this option's title when its parent is sent as a list (WhatsApp allows 72
            // characters). It is separate from `message`, which is what the bot replies after the option is tapped.
            $t->string('option_description', 72)->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', fn (Blueprint $t) => $t->dropColumn('option_description'));
    }
};
