<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Files a staff member keeps on their own profile (ID proof, certificates, contracts…). Private to that person and HR. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_staff_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('title', 150);
            $t->string('category', 30)->default('other');   // identity | address | education | experience | contract | other
            $t->string('path');
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->date('expires_on')->nullable();
            $t->timestamps();

            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staff_documents');
    }
};
