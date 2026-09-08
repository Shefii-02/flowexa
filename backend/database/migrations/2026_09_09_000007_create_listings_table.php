<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $t->string('type', 20)->default('product');        // property | service | course | product
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('status', 12)->default('active');        // draft | active | inactive | sold

            $t->decimal('price', 14, 2)->nullable();
            $t->string('price_unit', 20)->nullable();           // total | per_month | per_session | per_year
            $t->string('currency', 8)->default('INR');
            $t->string('location', 160)->nullable();

            // Vertical-specific fields, keyed per config/industry_templates.php attribute_schema.
            $t->json('attributes')->nullable();
            // [{ url, type:image|video, thumb? }]
            $t->json('media')->nullable();

            $t->string('source', 20)->default('manual');        // manual | instagram
            $t->string('external_ref', 80)->nullable();         // e.g. the Instagram media id
            $t->unsignedInteger('sort_order')->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'type', 'status']);
            $t->unique(['company_id', 'source', 'external_ref'], 'listings_company_source_ref_uq');
        });

        // Which vertical the AI sales agent / widget behaves as for this company.
        Schema::table('companies', function (Blueprint $t) {
            $t->string('industry_template', 30)->default('generic')->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('industry_template');
        });
        Schema::dropIfExists('listings');
    }
};
