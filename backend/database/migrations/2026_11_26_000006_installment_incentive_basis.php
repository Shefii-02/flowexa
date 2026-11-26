<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('installment_settings', function (Blueprint $t) {
            // how installments count toward INCENTIVES — 'same' follows target_basis; otherwise collected | full | first on their own
            $t->string('incentive_basis', 10)->default('same')->after('target_basis');
        });
        Schema::table('lead_installment_plans', function (Blueprint $t) {
            // payments = payments earn the target / incentive credit; upfront = the full credit was already given (and its incentive paid out) before the plan existed, so payments add nothing again
            $t->string('credit_mode', 10)->default('payments')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('lead_installment_plans', fn (Blueprint $t) => $t->dropColumn('credit_mode'));
        Schema::table('installment_settings', fn (Blueprint $t) => $t->dropColumn('incentive_basis'));
    }
};
