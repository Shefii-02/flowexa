<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prebuilt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Free-form category label, e.g. "auth", "utility", "other".
            // Intentionally a plain string (not an enum) so new categories can
            // be seeded without a schema change.
            $table->string('type', 30)->default('utility');
            $table->text('content');
            // Ordered, de-duplicated list of {{placeholder}} tokens used by `content`.
            $table->json('variables')->nullable();
            $table->string('language', 10)->default('en');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['name', 'language']);
            $table->index('type');
            $table->index('language');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prebuilt_templates');
    }
};
