<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            // Limited-time offer: the short label shown above the body ("Expiring offer!"). Null = not an offer template.
            $table->string('lto_text', 16)->nullable()->after('carousel_media');
            // Show the countdown / expiry line in the delivered message.
            $table->boolean('lto_has_expiration')->default(true)->after('lto_text');
            // Our own setting: how long after sending the offer code stays valid. Meta only gets the resulting timestamp.
            $table->unsignedSmallInteger('lto_expiry_hours')->default(72)->after('lto_has_expiration');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn(['lto_text', 'lto_has_expiration', 'lto_expiry_hours']);
        });
    }
};
