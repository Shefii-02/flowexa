<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales staff activity tracking:
 *  - lead_activities        every call / message / meeting a staff member logs on a lead
 *  - sales_activity_targets daily + monthly targets (one default row per company, optional per-employee overrides)
 *  - leads.cost             what this lead cost to get (ad spend etc.) — feeds "cost per converted lead"
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id');                 // who did it
            $table->string('kind', 20)->default('call');           // call | message | meeting | visit | other
            $table->string('outcome', 30)->nullable();             // connected | not_reachable | callback | interested | not_interested | wrong_number
            $table->boolean('is_first_contact')->default(false);   // the first logged activity on this lead = a "new lead call"; later ones are follow-ups
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'happened_at']);
            $table->index('lead_id');
        });

        Schema::create('sales_activity_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();     // null = the company default every sales staff member gets
            $table->unsignedInteger('daily_new_lead_calls')->default(0);
            $table->unsignedInteger('daily_follow_up_calls')->default(0);
            $table->unsignedInteger('monthly_calls')->default(0);
            $table->unsignedInteger('monthly_conversions')->default(0);
            $table->decimal('max_cost_per_conversion', 12, 2)->default(0);   // 0 = no limit
            $table->decimal('default_lead_cost', 12, 2)->default(0);         // used for a lead with no cost of its own (default row only)
            $table->timestamps();
            $table->index(['company_id', 'user_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->after('sale_value');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
        Schema::dropIfExists('sales_activity_targets');
        Schema::dropIfExists('lead_activities');
    }
};
