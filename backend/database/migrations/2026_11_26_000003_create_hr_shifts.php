<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Named shifts (Morning 9–6, Evening 2–11 …). A staff member on a shift is judged against THAT shift's start / end / grace for late
// clock-in, early leave and overtime; staff without one keep their own duty hours or the company hours, as before.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name', 60);
            $t->time('start_time');
            $t->time('end_time');                                  // earlier than start_time = a night shift that ends the next day
            $t->unsignedSmallInteger('grace_minutes')->nullable(); // null = the company's grace
            $t->string('color', 9)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->unique(['company_id', 'name']);
        });

        Schema::table('hr_staff_profiles', function (Blueprint $t) {
            $t->unsignedBigInteger('shift_id')->nullable()->after('duty_end');
            $t->foreign('shift_id')->references('id')->on('hr_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_staff_profiles', function (Blueprint $t) { $t->dropForeign(['shift_id']); $t->dropColumn('shift_id'); });
        Schema::dropIfExists('hr_shifts');
    }
};
