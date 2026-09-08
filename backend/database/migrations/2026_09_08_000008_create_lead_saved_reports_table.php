<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved "Advanced" lead-report definitions — a named set of filters + grouping
 * a user can re-run from the Leads → Advanced → Report page.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_saved_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 150);
            $table->json('filters')->nullable();       // { stage, source, category, assigned_to, from, to, ... }
            $table->string('group_by', 40)->default('stage'); // stage | source | category | agent | day
            $table->string('date_field', 20)->default('created_at'); // created_at | assigned_at | enrolled_at
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_saved_reports');
    }
};
