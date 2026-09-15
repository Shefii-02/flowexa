<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's auto-created "Admin" role (see CompanySetupService::syncDefaultRoles()) is meant
 * to be that company's de facto full-access tier — but since it's `is_system = false` (unlike
 * the global owner/admin roles), RoleController::update()/destroy() previously treated it as an
 * ordinary custom role: editable down to zero permissions, or deletable outright once it has no
 * users. `protected` marks that row (and any future role a company should never be able to leave
 * itself without) so RoleController can block full deletion and floor-permission edits on it,
 * the same way it already blocks edits to true system roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('protected')->default(false)->after('is_system');
        });

        // Backfill every company that existed before this migration — CompanySetupService
        // only sets `protected` on the row it creates going forward; without this, every
        // already-onboarded company's real "Admin" role stays unprotected indefinitely.
        \DB::table('roles')->whereNotNull('company_id')->where('name', 'Admin')->update(['protected' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('protected');
        });
    }
};
