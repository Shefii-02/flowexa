<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staff profile extras: designation (with a company-managed list), joining date, "accessible users" (whose leads this person may see), document types. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('designation', 100)->nullable()->after('department');
            $t->date('joined_on')->nullable()->after('designation');
            $t->json('accessible_user_ids')->nullable()->after('joined_on');   // staff ids whose leads this person may also see; NULL / [] = only their own
        });

        Schema::create('staff_designations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });

        Schema::create('staff_document_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_document_types');
        Schema::dropIfExists('staff_designations');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['designation', 'joined_on', 'accessible_user_ids']));
    }
};
