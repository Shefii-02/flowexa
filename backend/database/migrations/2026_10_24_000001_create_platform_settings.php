<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // Seed with the tiles currently hidden on mobile.
        DB::table('platform_settings')->insert([
            'key' => 'mobile_hidden_modules',
            'value' => json_encode([
                'wa_chat_api_services', 'wa_cloud_api_services', 'otp_service',
                'wa_chat_automations', 'wa_chat_analytics', 'wa_chat_audit',
                'wa_chat_export', 'templates',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
