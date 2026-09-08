<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp-style "linked devices" login for the mobile app.
 *
 *  - companies.max_devices_per_user : how many devices one user may keep linked
 *  - device_login_tokens            : the transient QR / PIN challenge (45s TTL)
 *  - user_devices                   : a linked device + its revocation state
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'max_devices_per_user')) {
                $table->unsignedTinyInteger('max_devices_per_user')->default(2);
            }
        });

        Schema::create('device_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');       // the user this login is FOR
            $table->unsignedBigInteger('created_by')->nullable(); // who generated it (self, or an admin)
            $table->char('token', 40)->unique();          // opaque value inside the QR
            $table->char('pin', 6);                       // manual-entry code
            $table->string('status', 12)->default('pending'); // pending | claimed | expired | cancelled
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('claimed_device_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['pin', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('device_uid', 128);            // stable id the app generates
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 12)->default('other'); // ios | android | web | other
            $table->string('app_version', 20)->nullable();
            $table->string('push_token', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedBigInteger('login_token_id')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'device_uid']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('device_login_tokens');
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'max_devices_per_user')) {
                $table->dropColumn('max_devices_per_user');
            }
        });
    }
};
