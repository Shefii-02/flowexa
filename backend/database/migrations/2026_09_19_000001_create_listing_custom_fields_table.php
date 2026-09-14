<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad-hoc key/value pairs a company adds to a single listing beyond the industry's fixed
 * attribute_schema (which stays in `listings.attributes`) — a one-off spec a particular listing
 * needs that the shared schema doesn't cover. Kept in their own table (rather than folded into
 * the `attributes` JSON column) so this "extra info" is queryable/reportable on its own and
 * doesn't get mixed up with the schema-driven fields the matcher and CSV import/export rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['listing_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_custom_fields');
    }
};
