<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the person left the to-do floating circle (separate from the to-do card's own position).
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->integer('todo_bubble_x')->nullable();
            $table->integer('todo_bubble_y')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->dropColumn(['todo_bubble_x', 'todo_bubble_y']);
        });
    }
};
