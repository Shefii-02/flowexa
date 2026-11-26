<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Every salary change is one row — the salary activity log AND the revision history: payroll for a month uses the revision whose
        // effective month is the latest one not after that month. effective_from is always the 1st of a month.
        Schema::create('hr_salary_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->date('effective_from');
            $t->decimal('monthly_salary', 12, 2)->nullable();
            $t->decimal('hourly_rate', 10, 2)->nullable();
            $t->decimal('prev_monthly_salary', 12, 2)->nullable();
            $t->decimal('prev_hourly_rate', 10, 2)->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->string('note', 255)->nullable();
            $t->string('source', 20)->default('staff');   // staff (form / details) | setup (HR Staff Setup) | system
            $t->timestamps();
            $t->index(['company_id', 'user_id', 'effective_from']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Monthly sales target per person: month NULL = the common target that applies to every month; a row with a month (YYYY-MM) wins for that month.
        Schema::create('hr_staff_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->char('month', 7)->nullable();
            $t->decimal('monthly_target', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'month']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Target + incentive % per lead category per person (category = the lead category's name, as leads store it). month NULL = every month.
        Schema::create('hr_category_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('category', 100);
            $t->char('month', 7)->nullable();
            $t->decimal('target_amount', 14, 2)->default(0);
            $t->decimal('incentive_percent', 5, 2)->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'category', 'month']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_category_targets');
        Schema::dropIfExists('hr_staff_targets');
        Schema::dropIfExists('hr_salary_history');
    }
};
