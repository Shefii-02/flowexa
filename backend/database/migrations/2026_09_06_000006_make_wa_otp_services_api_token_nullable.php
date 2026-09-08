<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `wa_otp_services.api_token` shipped NOT NULL with no default, so the very
 * first `firstOrCreate(['company_id' => …])` in the Api Service controllers
 * (and `stopToken()` setting it back to null) blew up. A company has no token
 * until it explicitly generates one — make the column nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE wa_otp_services MODIFY api_token VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE wa_otp_services SET api_token = '' WHERE api_token IS NULL");
        DB::statement('ALTER TABLE wa_otp_services MODIFY api_token VARCHAR(255) NOT NULL');
    }
};
