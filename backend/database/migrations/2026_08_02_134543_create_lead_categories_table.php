<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 7)->default('#1D9E75'); // hex color
            $table->text('description')->nullable();
            $table->unsignedInteger('leads_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // Keep existing 'category' varchar for backward compat
            // Add FK for structured category
            $table->foreignId('lead_category_id')
                ->nullable()
                ->after('category')
                ->constrained('lead_categories')
                ->nullOnDelete();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_categories');
        Schema::dropIfExists('lead_categories');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lead_category_id']);
            $table->dropColumn('lead_category_id');
        });
    }
};
