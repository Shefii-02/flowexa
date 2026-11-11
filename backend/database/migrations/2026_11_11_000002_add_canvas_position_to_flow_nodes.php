<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a node sits on the drag-and-drop canvas. Null = not placed yet (the canvas auto-lays it out).
        Schema::table('flow_nodes', function (Blueprint $t) {
            $t->double('canvas_x')->nullable();
            $t->double('canvas_y')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', fn (Blueprint $t) => $t->dropColumn(['canvas_x', 'canvas_y']));
    }
};
