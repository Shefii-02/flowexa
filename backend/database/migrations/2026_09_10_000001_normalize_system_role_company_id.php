<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System (global) roles must use `company_id = NULL`, not 0.
 *
 * Some environments seeded them with `company_id = 0`, which has no matching
 * `companies` row — it breaks the `roles.company_id` foreign key where the FK
 * is present, and is semantically wrong (0 is not "no company").
 *
 * Makes the column nullable where it isn't, then converts `company_id = 0`
 * roles to NULL (skipping any whose canonical NULL twin already exists, to
 * avoid a (company_id, name) unique collision — those are left for manual
 * review rather than deleting a role that users/pivots may reference).
 * Idempotent and safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'company_id')) {
            return;
        }

        $column = collect(DB::select("SHOW COLUMNS FROM `roles` WHERE Field = 'company_id'"))->first();
        if ($column && strtoupper($column->Null) === 'NO') {
            DB::statement('ALTER TABLE `roles` MODIFY `company_id` BIGINT UNSIGNED NULL');
        }

        $nullNames = DB::table('roles')->whereNull('company_id')->pluck('name')->all();

        DB::table('roles')
            ->where('company_id', 0)
            ->when($nullNames, fn ($q) => $q->whereNotIn('name', $nullNames))
            ->update(['company_id' => null]);

        $stuck = DB::table('roles')->where('company_id', 0)->count();
        if ($stuck > 0) {
            // Not fatal — the seeder still works — but worth knowing about.
            echo "  ⚠️  {$stuck} role(s) still at company_id = 0 (a NULL twin already exists). Review manually.\n";
        }
    }

    public function down(): void
    {
        // NULL is the correct value — nothing to roll back.
    }
};
