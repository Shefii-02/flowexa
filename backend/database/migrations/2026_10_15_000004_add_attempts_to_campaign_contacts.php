<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            // Send attempts used on the last run, and why it finally failed
            // (network | not_on_whatsapp | not_allowed | bad_number | template | billing | auth | error).
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->string('error_type', 30)->nullable()->after('failed_reason');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'error_type']);
        });
    }
};
