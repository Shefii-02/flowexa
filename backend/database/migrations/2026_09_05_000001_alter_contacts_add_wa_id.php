<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// wa_id is already declared in the original create_waapi_tables migration, but that file can only
// be trusted for a database that was migrated fresh from it — an existing database whose contacts
// table was created before wa_id was added there would not have picked it up. Guarded so this is a
// no-op wherever the column already exists.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'wa_id')) {
                $table->string('wa_id', 30)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'wa_id')) {
                $table->dropColumn('wa_id');
            }
        });
    }
};
