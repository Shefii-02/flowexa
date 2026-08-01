<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{


    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            // Header format — TEXT (default), IMAGE, VIDEO, DOCUMENT, LOCATION
            $table->string('header_format', 20)->default('TEXT')->after('header');

            // Media header sample — Meta's uploaded-media handle used at submission time,
            // plus the URL we store locally so "edit" can show what was uploaded before
            $table->string('header_handle')->nullable()->after('header_format');
            $table->string('header_sample_path')->nullable()->after('header_handle');
            $table->string('header_sample_url')->nullable()->after('header_sample_path');

            // Sample value for the single {{1}} variable allowed in a TEXT header
            $table->string('header_example')->nullable()->after('header_sample_url');

            // Ordered sample values for body {{1}}, {{2}}, ... — index 0 = {{1}}
            $table->json('body_examples')->nullable()->after('body');

            // Buttons were never persisted before — needed to round-trip on edit
            $table->json('buttons')->nullable()->after('footer');

            // Controller writes 'rejection_reason', sync writes 'rejected_reason' from Meta —
            // standardize on one column so nothing silently goes to a non-existent field
            if (!Schema::hasColumn('wa_templates', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn([
                'header_format', 'header_handle', 'header_sample_path',
                'header_sample_url', 'header_example', 'body_examples', 'buttons',
            ]);
        });
    }
};
