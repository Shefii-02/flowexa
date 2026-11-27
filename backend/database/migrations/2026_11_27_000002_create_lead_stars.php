<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A personal star on a lead: each user keeps their own set of starred leads (others can't see or change them).
        Schema::create('lead_stars', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('lead_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'lead_id']);
            $t->index(['company_id', 'user_id']);
            $t->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('lead_stars'); }
};
