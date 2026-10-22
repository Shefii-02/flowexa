<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether each floating circle (sticky notes / to-do) is shown at all. On by default.
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->boolean('notes_enabled')->default(true);
            $table->boolean('todo_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->dropColumn(['notes_enabled', 'todo_enabled']);
        });
    }
};
