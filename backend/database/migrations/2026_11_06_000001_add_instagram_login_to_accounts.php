<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $t) {
            $t->string('auth_type', 20)->default('page')->after('ig_user_id');   // page | instagram_login
            $t->string('status', 20)->default('active')->after('is_active');     // active | token_expired | disconnected
            $t->json('scopes')->nullable();
            $t->boolean('webhook_subscribed')->default(false);
            $t->timestamp('token_refreshed_at')->nullable();
        });

        Schema::create('instagram_data_deletions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('ig_user_id', 40);
            $t->string('status', 12)->default('pending');   // pending | completed
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_data_deletions');
        Schema::table('instagram_accounts', function (Blueprint $t) {
            $t->dropColumn(['auth_type', 'status', 'scopes', 'webhook_subscribed', 'token_refreshed_at']);
        });
    }
};
