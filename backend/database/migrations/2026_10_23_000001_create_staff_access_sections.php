<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Access sections" — prebuilt permission bundles (config/access_sections.php) layered on top of a
 * staff member's role — plus per-staff switches for which channel (web portal / mobile app) they may use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_access_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('section_key', 40);
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'section_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('portal_access')->default(true)->after('is_active');
            $table->boolean('mobile_access')->default(true)->after('portal_access');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['portal_access', 'mobile_access']);
        });
        Schema::dropIfExists('staff_access_sections');
    }
};
