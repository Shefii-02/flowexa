<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The to-do sticky card: on/off and where the person left it.
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->boolean('todo_visible')->default(false);
            $table->integer('todo_x')->nullable();
            $table->integer('todo_y')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->dropColumn(['todo_visible', 'todo_x', 'todo_y']);
        });
    }
};
