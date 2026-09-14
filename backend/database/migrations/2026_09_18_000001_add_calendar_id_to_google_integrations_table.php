<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar booking reuses the SAME per-company Google connection Drive/Sheets already use
 * (GoogleClient::SCOPES now also requests the Calendar scope) rather than a second OAuth flow —
 * one Google account, one consent screen, per company. `calendar_id` lets a company point future
 * bookings at a calendar other than the account's default "primary" one; null means "primary".
 * Companies that connected before this scope existed simply get a 403 on their first booking
 * attempt until they reconnect — handled as a soft failure in CalendarService, never blocking
 * the local booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_integrations', function (Blueprint $table) {
            $table->string('calendar_id')->nullable()->after('drive_folder_url');
        });
    }

    public function down(): void
    {
        Schema::table('google_integrations', function (Blueprint $table) {
            $table->dropColumn('calendar_id');
        });
    }
};
