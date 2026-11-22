<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Also save every new lead to a Google Sheet" (Assignment Rules → Lead sheet). Each lead is written to the database as always AND appended
 * to this sheet as it arrives, one row per lead. One row per company; the sheet id is kept if the switch is turned off and on again.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_sheet_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('is_enabled')->default(false);
            $t->string('spreadsheet_id', 100)->nullable();
            $t->string('sheet_url', 300)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sheet_settings');
    }
};
