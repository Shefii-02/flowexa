<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('openwa_message_logs', function (Blueprint $table) {
            // How many send attempts this recipient took, and why it finally failed
            // (network | not_on_whatsapp | bad_number | auth | error). Null error_type = delivered.
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
            $table->string('error_type', 30)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('openwa_message_logs', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'error_type']);
        });
    }
};
