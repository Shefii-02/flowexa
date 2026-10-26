<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Show / hide the floating "Ask in portal" assistant button. On by default.
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->boolean('assistant_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sticky_note_settings', fn (Blueprint $table) => $table->dropColumn('assistant_enabled'));
    }
};
