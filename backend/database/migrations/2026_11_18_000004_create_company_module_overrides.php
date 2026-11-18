<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A row here = superadmin switched this feature OFF for this company, whatever its plan, add-ons or complimentary period say. Delete the row to switch it back on.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_module_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('module', 50);
            $t->unsignedBigInteger('disabled_by')->nullable();
            $t->string('disabled_by_name', 100)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'module']);
        });
    }

    public function down(): void { Schema::dropIfExists('company_module_overrides'); }
};
