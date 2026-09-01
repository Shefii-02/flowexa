<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'description')) {
                $table->string('description', 255)->nullable()->after('label');
            }
            if (!Schema::hasColumn('roles', 'color')) {
                $table->string('color', 7)->default('#6366f1')->after('description');
            }
            if (!Schema::hasColumn('roles', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('color');
            }
            if (!Schema::hasColumn('roles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(array_filter(['description', 'color', 'sort_order', 'is_active'], fn($col) => Schema::hasColumn('roles', $col)));
        });
    }
};
