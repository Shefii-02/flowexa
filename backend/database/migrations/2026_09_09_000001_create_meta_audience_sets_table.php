<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_audience_sets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('meta_ad_account_id')->nullable()->constrained('meta_ad_accounts')->nullOnDelete();
            // Which system starting point this was cloned from, if any.
            $t->foreignId('template_id')->nullable()->constrained('meta_audience_templates')->nullOnDelete();

            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->string('source', 20)->default('manual'); // manual | template | from_adset

            // ── Normalized targeting (drives the editable "customize" form) ──────
            $t->unsignedSmallInteger('age_min')->default(18);
            $t->unsignedSmallInteger('age_max')->default(65);
            $t->string('genders', 10)->default('all'); // all | male | female
            $t->json('geo_locations')->nullable();               // { countries, regions[], cities[], custom_locations[], location_types[] }
            $t->json('interests')->nullable();                   // [{ id, name }]
            $t->json('behaviors')->nullable();                   // [{ id, name }]
            $t->json('locales')->nullable();                     // [int] Meta locale ids
            $t->json('custom_audiences')->nullable();            // [{ id, name }]
            $t->json('excluded_custom_audiences')->nullable();   // [{ id, name }]
            $t->json('flexible_spec')->nullable();               // advanced AND-of-OR groups, verbatim Meta shape
            $t->json('exclusions')->nullable();                  // { interests[], behaviors[] } to narrow out
            $t->json('placements')->nullable();                  // { automatic:bool, publisher_platforms[], facebook_positions[], instagram_positions[], device_platforms[] }

            // Compiled Meta targeting object — cached so a one-click apply needs no recompute.
            $t->json('targeting_spec')->nullable();

            // Last delivery estimate pulled from Meta for this definition.
            $t->unsignedBigInteger('reach_min')->nullable();
            $t->unsignedBigInteger('reach_max')->nullable();
            $t->timestamp('reach_estimated_at')->nullable();

            $t->boolean('is_favorite')->default(false);
            $t->unsignedInteger('use_count')->default(0);
            $t->timestamp('last_used_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_favorite']);
            $t->index(['company_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_audience_sets');
    }
};
