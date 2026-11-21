<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. The superadmin's Auth / Utility template library says which channel each template is for — tick WA Chat, WA Cloud or both. A company's
 *    API configs only list (and may only pick) templates ticked for that channel. Existing templates stay available to both.
 * 2. WA Chat API Service access: besides the allowed domains, the company can allow or block calls from curl / Postman / the terminal.
 *    Those clients send no Origin header, which until now was only blocked as a side effect of listing domains — so services that
 *    already list domains keep blocking them (allow_non_browser = false), everyone else keeps allowing them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prebuilt_templates', function (Blueprint $t) {
            $t->boolean('for_wa_chat')->default(true)->after('status');
            $t->boolean('for_wa_cloud')->default(true)->after('for_wa_chat');
        });

        Schema::table('openwa_otp_services', function (Blueprint $t) {
            $t->boolean('allow_non_browser')->default(true)->after('allowed_packages');
        });
        foreach (DB::table('openwa_otp_services')->whereNotNull('allowed_domains')->get(['id', 'allowed_domains']) as $s) {
            $domains = json_decode((string) $s->allowed_domains, true);
            if (is_array($domains) && count(array_filter($domains)) > 0) {
                DB::table('openwa_otp_services')->where('id', $s->id)->update(['allow_non_browser' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('openwa_otp_services', fn (Blueprint $t) => $t->dropColumn('allow_non_browser'));
        Schema::table('prebuilt_templates', fn (Blueprint $t) => $t->dropColumn(['for_wa_chat', 'for_wa_cloud']));
    }
};
