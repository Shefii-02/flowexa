<?php
// ════════════════════════════════════════════════════════════════════════════
// Firebase Push Notifications
// ════════════════════════════════════════════════════════════════════════════

// MIGRATION: database/migrations/2024_01_01_000004_create_push_tokens_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->text('fcm_token');                     // Firebase Cloud Messaging token
            $t->string('device_type', 20)->default('android'); // android | ios | web
            $t->string('device_id', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'is_active']);
            $t->index(['company_id', 'is_active']);
        });

        Schema::create('push_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();  // null = all staff
            $t->string('type', 50);           // lead_assigned | lead_stage_change | campaign_complete | low_balance
            $t->string('title', 150);
            $t->text('body');
            $t->json('data')->nullable();     // extra payload (lead_id, campaign_id, etc.)
            $t->string('status', 20)->default('sent');  // sent | failed
            $t->unsignedInteger('sent_count')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'type']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notifications');
        Schema::dropIfExists('push_tokens');
    }
};
