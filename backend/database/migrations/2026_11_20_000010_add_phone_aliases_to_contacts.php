<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WhatsApp Chat contact that arrives with a private "@lid" id has no phone number — its "phone" is just the digits of that id.
 * Staff can now replace it with the real number; the old value is kept here so the next message from that same chat still finds
 * this contact instead of creating a duplicate contact (and a duplicate lead).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('phone_aliases')->nullable()->after('wa_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('phone_aliases');
        });
    }
};
