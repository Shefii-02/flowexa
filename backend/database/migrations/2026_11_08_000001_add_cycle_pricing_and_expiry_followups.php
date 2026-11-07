<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yearly prices are set by hand (null = fall back to the old rule: plans 12 × monthly × 0.8, add-ons 12 × monthly).
        Schema::table('plans', fn (Blueprint $t) => $t->decimal('price_yearly', 10, 2)->nullable()->after('price'));
        Schema::table('addons', fn (Blueprint $t) => $t->decimal('price_yearly', 10, 2)->nullable()->after('price'));
        // Which cycle the company bought (drives expiry and renewal).
        Schema::table('company_addons', fn (Blueprint $t) => $t->string('billing_cycle', 12)->default('monthly')->after('status'));

        // Superadmin staff note when they told a company its plan / add-on is about to lapse.
        Schema::create('plan_expiry_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                       // plan | trial | addon
            $t->unsignedBigInteger('reference_id')->nullable();   // company_addons.id for kind = addon
            $t->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamp('contacted_at')->useCurrent();
            $t->timestamps();
            $t->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_expiry_followups');
        Schema::table('company_addons', fn (Blueprint $t) => $t->dropColumn('billing_cycle'));
        Schema::table('addons', fn (Blueprint $t) => $t->dropColumn('price_yearly'));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('price_yearly'));
    }
};
