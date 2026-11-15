<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            // Features given free for a limited time: [{module, months}]. NULL / empty = none.
            $t->json('complimentary')->nullable()->after('permissions');
        });

        // One row per company per feature: the free period starts the first time the company is on a plan that offers it, and is never restarted.
        Schema::create('company_complimentary', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('module', 50);
            $t->unsignedBigInteger('plan_id')->nullable();
            $t->unsignedSmallInteger('months');
            $t->timestamp('starts_at');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['company_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_complimentary');
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('complimentary'));
    }
};
