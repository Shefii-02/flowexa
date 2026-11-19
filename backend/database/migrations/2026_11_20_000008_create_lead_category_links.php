<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A lead can belong to several categories. leads.category stays as the PRIMARY (first) one — everything that reads a single
 * category keeps working — and this table holds ALL of a lead's categories, primary included.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_category_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id');
            $table->string('name', 120);                 // leads point at a category by NAME (lead_categories.name), as leads.category always has
            $table->timestamps();

            $table->unique(['lead_id', 'name']);
            $table->index(['company_id', 'name']);
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        // every existing lead's single category becomes its first link
        DB::statement("INSERT INTO lead_category_links (company_id, lead_id, name, created_at, updated_at)
                       SELECT company_id, id, category, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM leads WHERE category IS NOT NULL AND category <> ''");
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_category_links');
    }
};
