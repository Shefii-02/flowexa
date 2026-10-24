<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** roles.access_main / access_subs : default access sections (config/access_sections.php) for everyone holding the role. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'access_main')) $table->string('access_main', 40)->nullable();
            if (!Schema::hasColumn('roles', 'access_subs')) $table->json('access_subs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            foreach (['access_main', 'access_subs'] as $c) {
                if (Schema::hasColumn('roles', $c)) $table->dropColumn($c);
            }
        });
    }
};
