<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_insights', function (Blueprint $t) {
            $t->decimal('frequency', 8, 4)->default(0)->after('reach');
            $t->unsignedBigInteger('link_clicks')->default(0)->after('unique_clicks');
            $t->decimal('cost_per_lead', 10, 4)->default(0)->after('leads');
            $t->unsignedBigInteger('thruplays')->default(0)->after('video_views');
        });

        // Encrypt any ad-account access tokens still stored in plaintext. Idempotent: a value that
        // already decrypts is left alone, so re-running (or running after the model cast is live)
        // is a no-op.
        foreach (DB::table('meta_ad_accounts')->select('id', 'access_token')->get() as $row) {
            if (empty($row->access_token)) {
                continue;
            }
            try {
                Crypt::decryptString($row->access_token);
                continue; // already encrypted
            } catch (\Throwable) {
                // plaintext — encrypt it
            }
            DB::table('meta_ad_accounts')
                ->where('id', $row->id)
                ->update(['access_token' => Crypt::encryptString($row->access_token)]);
        }
    }

    public function down(): void
    {
        Schema::table('meta_insights', function (Blueprint $t) {
            $t->dropColumn(['frequency', 'link_clicks', 'cost_per_lead', 'thruplays']);
        });

        foreach (DB::table('meta_ad_accounts')->select('id', 'access_token')->get() as $row) {
            if (empty($row->access_token)) {
                continue;
            }
            try {
                $plain = Crypt::decryptString($row->access_token);
            } catch (\Throwable) {
                continue;
            }
            DB::table('meta_ad_accounts')->where('id', $row->id)->update(['access_token' => $plain]);
        }
    }
};
