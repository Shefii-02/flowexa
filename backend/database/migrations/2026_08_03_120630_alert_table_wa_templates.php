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
        //

        Schema::table('wa_templates', function (Blueprint $table) {
            // Only meaningful when category = AUTHENTICATION.
            // 'copy_code' = basic (default), 'one_tap' = one-tap autofill, 'zero_tap' = zero-tap autofill
            $table->string('auth_delivery_method', 20)->nullable()->after('buttons')
                ->comment('copy_code | one_tap | zero_tap — only used for AUTHENTICATION templates');

            // Meta's FOOTER.code_expiration_minutes — defaults to 20, toggle to disable entirely
            $table->boolean('auth_add_expiry')->default(true)->after('auth_delivery_method');
            $table->unsignedTinyInteger('auth_code_expiration_minutes')->default(20)->after('auth_add_expiry');

            // Meta's BODY.add_security_recommendation
            $table->boolean('auth_add_security_recommendation')->default(true)->after('auth_code_expiration_minutes');

            // Required for one_tap/zero_tap: up to 5 {package_name, signature_hash} pairs
            $table->json('auth_apps')->nullable()->after('auth_add_security_recommendation');

            // Meta requires explicit acceptance of the zero-tap terms before submission
            $table->boolean('auth_zero_tap_terms_accepted')->default(false)->after('auth_apps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn([
                'auth_delivery_method',
                'auth_add_expiry',
                'auth_code_expiration_minutes',
                'auth_add_security_recommendation',
                'auth_apps',
                'auth_zero_tap_terms_accepted',
            ]);
        });
    }
};
