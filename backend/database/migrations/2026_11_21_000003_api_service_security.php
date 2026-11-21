<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA Chat API Service security that does not depend on headers the caller writes (Origin / User-Agent can be faked by anyone with the token):
 *   allowed_ips        the caller's network address — it cannot be faked on a TCP connection (IPs and CIDR ranges)
 *   require_signature  every request must carry X-Timestamp + X-Signature = HMAC-SHA256 of the body with a secret that never travels,
 *                      so a leaked token alone is useless; each signature works once and only for 5 minutes
 *   signing_secret     that secret (encrypted at rest)
 *   rate_per_minute    requests per minute for the whole service (0 = unlimited) — stops a leaked token draining the wallet
 *   max_per_phone_hour messages to one number per hour (0 = unlimited) — stops OTP bombing someone
 * All off / generous by default, so existing integrations keep working unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('openwa_otp_services', function (Blueprint $t) {
            $t->json('allowed_ips')->nullable()->after('allow_non_browser');
            $t->boolean('require_signature')->default(false)->after('allowed_ips');
            $t->text('signing_secret')->nullable()->after('require_signature');
            $t->unsignedSmallInteger('rate_per_minute')->default(120)->after('signing_secret');
            $t->unsignedSmallInteger('max_per_phone_hour')->default(20)->after('rate_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('openwa_otp_services', fn (Blueprint $t) => $t->dropColumn(['allowed_ips', 'require_signature', 'signing_secret', 'rate_per_minute', 'max_per_phone_hour']));
    }
};
