<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit trail of validity extended by hand (plan expiry or a complimentary feature) so staff can see it was extended, by whom, and why.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_plan_extensions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);                 // plan | complimentary
            $t->string('module', 50)->nullable();   // set for complimentary
            $t->timestamp('old_expires_at')->nullable();
            $t->timestamp('new_expires_at');
            $t->integer('days');
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('extended_by')->nullable();
            $t->string('extended_by_name', 100)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'kind']);
        });
    }

    public function down(): void { Schema::dropIfExists('company_plan_extensions'); }
};
