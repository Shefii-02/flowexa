<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A lead can be referred by an existing contact (a customer, a partner…) — leads.referred_by_contact_id. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_by_contact_id')->nullable()->after('contact_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('referred_by_contact_id');
        });
    }
};
