<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Count limits — null = unlimited, same convention as the existing max_* columns.
            $table->unsignedInteger('max_leads_per_month')->nullable()->after('max_campaign_contacts');
            $table->unsignedSmallInteger('max_lead_categories')->nullable()->after('max_leads_per_month');
            $table->unsignedSmallInteger('max_roles')->nullable()->after('max_lead_categories');
            $table->unsignedSmallInteger('max_instagram_accounts')->nullable()->after('max_roles');
            $table->unsignedSmallInteger('max_meta_ads_accounts')->nullable()->after('max_instagram_accounts');
            $table->unsignedSmallInteger('max_website_widgets')->nullable()->after('max_meta_ads_accounts');

            // Feature toggles — default true so no existing company loses access to an
            // integration it already uses when this migration runs.
            $table->boolean('google_sheets_enabled')->default(true)->after('max_meta_ads_accounts');
            $table->boolean('google_drive_enabled')->default(true)->after('google_sheets_enabled');
            $table->boolean('calendar_enabled')->default(true)->after('google_drive_enabled');
            $table->boolean('email_integration_enabled')->default(true)->after('calendar_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'max_leads_per_month',
                'max_lead_categories',
                'max_roles',
                'max_instagram_accounts',
                'max_meta_ads_accounts',
                'max_website_widgets',
                'google_sheets_enabled',
                'google_drive_enabled',
                'calendar_enabled',
                'email_integration_enabled',
            ]);
        });
    }
};
