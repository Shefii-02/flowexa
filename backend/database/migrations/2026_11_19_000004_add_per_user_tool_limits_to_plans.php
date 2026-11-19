<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-USER caps for the common tools: sticky notes each staff member may keep, and open (not completed) to-do items each may have. NULL = unlimited.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('max_sticky_notes_per_user')->nullable();
            $t->unsignedInteger('max_todos_per_user')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['max_sticky_notes_per_user', 'max_todos_per_user']));
    }
};
