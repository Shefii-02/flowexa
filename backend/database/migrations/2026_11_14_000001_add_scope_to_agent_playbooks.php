<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_playbooks', function (Blueprint $t) {
            // What this agent is for (general | sales | support | hr | finance | custom) and where it answers.
            $t->string('function_key', 20)->default('general')->after('business_type');
            $t->json('channels')->nullable()->after('function_key');      // NULL = every channel; else any of wa_chat, wa_cloud, instagram, widget
        });
    }

    public function down(): void
    {
        Schema::table('agent_playbooks', fn (Blueprint $t) => $t->dropColumn(['function_key', 'channels']));
    }
};
