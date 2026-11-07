<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_messages', function (Blueprint $t) {
            $t->string('reply_to_mid', 255)->nullable();   // the message this one answers (quoted reply)
            $t->string('my_reaction', 20)->nullable();     // reaction our side left on this message ("love" …)
        });
    }

    public function down(): void
    {
        Schema::table('instagram_messages', fn (Blueprint $t) => $t->dropColumn(['reply_to_mid', 'my_reaction']));
    }
};
