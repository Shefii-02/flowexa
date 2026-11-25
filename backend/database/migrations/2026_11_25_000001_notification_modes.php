<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // how each kind of notification reaches a person: {"lead":"sound","conversation":"silent","general":"off"} (sound | silent | off; missing = sound)
        Schema::table('notification_preferences', function (Blueprint $t) { $t->json('modes')->nullable()->after('attendance_reminders'); });
        // the same, but for ONE phone / browser — it overrides the person's own setting on that device only
        Schema::table('push_tokens', function (Blueprint $t) {
            $t->json('modes')->nullable();
            $t->string('device_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $t) { $t->dropColumn('modes'); });
        Schema::table('push_tokens', function (Blueprint $t) { $t->dropColumn(['modes', 'device_name']); });
    }
};
