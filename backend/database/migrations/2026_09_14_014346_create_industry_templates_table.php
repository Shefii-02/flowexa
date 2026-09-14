<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the industry/business-type catalog templates (previously hardcoded in
 * config/industry_templates.php) into the database, so SuperAdmin can manage them —
 * add a new vertical, tweak an attribute schema — without a code deploy. See
 * App\Modules\Catalog\IndustryTemplates, which now reads from this table instead of the
 * config file while keeping the exact same static API every existing caller already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('listing_type')->default('product');
            $table->json('attribute_schema')->nullable();
            $table->json('qualification_fields')->nullable();
            $table->json('question_flow')->nullable();
            $table->text('agent_prompt')->nullable();
            $table->string('lead_source')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_templates');
    }
};
