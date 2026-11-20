<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finding the real number behind a WhatsApp privacy id ("…@lid") now happens on the server (at lead creation and on an hourly sweep),
 * not only when someone opens the lead. These record the last attempt so the sweep does not hammer the gateway for numbers that cannot
 * be resolved: status = saved | unresolved | conflict.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->timestamp('phone_lookup_at')->nullable();
            $t->string('phone_lookup_status', 12)->nullable();
            $t->index(['phone_lookup_status', 'phone_lookup_at'], 'contacts_phone_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropIndex('contacts_phone_lookup_idx');
            $t->dropColumn(['phone_lookup_at', 'phone_lookup_status']);
        });
    }
};
