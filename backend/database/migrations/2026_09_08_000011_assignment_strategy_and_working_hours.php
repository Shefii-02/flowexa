<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - `lead_assignment_rules.strategy` — round_robin (Basic: equal, one-by-one)
 *   vs algorithm (Advanced: weighted Uber-style ranking).
 * - Per-weekday company working hours + date-specific holiday overrides, which
 *   the lead-assignment engine now respects.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_assignment_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('lead_assignment_rules', 'strategy')) {
                $table->string('strategy', 20)->default('round_robin')->after('auto_assign_enabled');
            }
        });

        Schema::create('company_working_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedTinyInteger('weekday');  // 0 = Sunday … 6 = Saturday
            $table->boolean('is_open')->default(true);
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('18:00:00');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'weekday']);
        });

        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('date');
            $table->string('name', 120);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
        Schema::dropIfExists('company_working_hours');
        Schema::table('lead_assignment_rules', function (Blueprint $table) {
            if (Schema::hasColumn('lead_assignment_rules', 'strategy')) {
                $table->dropColumn('strategy');
            }
        });
    }
};
