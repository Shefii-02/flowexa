<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_conversion_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('phone', 30);
            $table->enum('event_type', [
                'score_increased', 'score_decreased', 'intent_changed',
                'buying_signal_detected', 'objection_detected', 'stage_changed',
                'auto_qualified', 'auto_task_created', 'handed_to_human',
                'converted', 'lost',
            ]);
            $table->string('from_value', 100)->nullable();
            $table->string('to_value', 100)->nullable();
            $table->text('trigger_message')->nullable();
            $table->unsignedBigInteger('analysis_id')->nullable();
            $table->boolean('automated')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'contact_id', 'event_type']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_conversion_events');
    }
};
