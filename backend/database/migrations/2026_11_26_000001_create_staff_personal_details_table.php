<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Personal + parents details collected for each staff member (the work side — phone, designation, joining date — stays on users).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_personal_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->unique();
            $t->json('alternate_phones')->nullable();          // extra numbers: [{label, number}]
            $t->date('dob')->nullable();
            $t->string('gender', 12)->nullable();
            $t->string('blood_group', 5)->nullable();
            $t->string('marital_status', 15)->nullable();
            $t->text('address')->nullable();
            $t->string('city', 80)->nullable();
            $t->string('state', 80)->nullable();
            $t->string('pincode', 12)->nullable();
            $t->string('father_name', 120)->nullable();
            $t->string('father_phone', 20)->nullable();
            $t->string('mother_name', 120)->nullable();
            $t->string('mother_phone', 20)->nullable();
            $t->string('emergency_name', 120)->nullable();
            $t->string('emergency_phone', 20)->nullable();
            $t->string('emergency_relation', 40)->nullable();
            $t->json('other_numbers')->nullable();             // any further numbers to keep: [{label, number}]
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('staff_personal_details'); }
};
