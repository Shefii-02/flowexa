<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user sidebar: collapsible accordions on/off, and which menu sections this user wants to see.
        Schema::table('sticky_note_settings', function (Blueprint $table) {
            $table->boolean('sidebar_accordion')->default(true);
            $table->json('sidebar_hidden')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sticky_note_settings', fn (Blueprint $table) => $table->dropColumn(['sidebar_accordion', 'sidebar_hidden']));
    }
};
