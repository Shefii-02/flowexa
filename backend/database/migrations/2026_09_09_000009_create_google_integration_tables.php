<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One connected Google account per company (their own Drive & Sheets).
        Schema::create('google_integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('google_email', 200)->nullable();
            $t->text('access_token');                          // encrypted via cast
            $t->text('refresh_token')->nullable();             // encrypted via cast
            $t->timestamp('token_expires_at')->nullable();
            $t->json('scopes')->nullable();
            $t->string('drive_folder_id', 80)->nullable();
            $t->string('drive_folder_url', 300)->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('last_error')->nullable();
            $t->timestamps();
        });

        // Each row = one auto-synced Google Sheet (leads / widget leads / meta / instagram).
        Schema::create('google_sheet_syncs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('google_integration_id')->constrained('google_integrations')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('source', 20)->default('leads');        // leads | widget | meta_leads | instagram
            $t->string('spreadsheet_id', 80)->nullable();
            $t->string('sheet_url', 300)->nullable();
            $t->json('filters')->nullable();
            $t->unsignedBigInteger('last_synced_id')->default(0);
            $t->unsignedInteger('last_row_count')->default(0);
            $t->unsignedSmallInteger('interval_hours')->default(6);
            $t->timestamp('last_synced_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index(['is_active', 'last_synced_at'], 'gsheet_sync_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheet_syncs');
        Schema::dropIfExists('google_integrations');
    }
};
