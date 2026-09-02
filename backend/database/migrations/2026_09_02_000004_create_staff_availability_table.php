<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_availability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('staff_id')->unique();
            $table->boolean('is_online')->default(false);
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('current_leads_count')->default(0);
            $table->integer('today_leads_count')->default(0);
            $table->integer('today_conversions')->default(0);
            $table->integer('total_conversions')->default(0);
            $table->decimal('avg_response_time_minutes', 8, 2)->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0);
            $table->decimal('performance_score', 5, 2)->default(50);
            $table->enum('status', ['online','away','offline','busy'])->default('offline');
            $table->timestamps();

            $table->index(['company_id', 'is_available', 'status']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_availability');
    }
};
