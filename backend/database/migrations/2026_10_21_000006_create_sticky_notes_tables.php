<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal sticky notes: each belongs to ONE user (nobody else in the company sees them).
        Schema::create('sticky_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title', 100)->nullable();
            $table->text('content')->nullable();
            $table->string('color', 9)->default('#fde68a');   // card background, #rrggbb
            $table->integer('x')->default(120);               // where the card sits on screen (px from the top-left)
            $table->integer('y')->default(120);
            $table->unsignedSmallInteger('width')->default(240);
            $table->unsignedSmallInteger('height')->default(200);
            $table->boolean('minimized')->default(false);     // folded down to just its title bar
            $table->unsignedInteger('sort_order')->default(0); // later = on top
            $table->timestamps();
        });

        // Per-user widget state: where the floating circle was left, and whether the notes are showing.
        Schema::create('sticky_note_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->integer('bubble_x')->nullable();
            $table->integer('bubble_y')->nullable();
            $table->boolean('notes_visible')->default(false);
            $table->string('default_color', 9)->default('#fde68a');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticky_note_settings');
        Schema::dropIfExists('sticky_notes');
    }
};
