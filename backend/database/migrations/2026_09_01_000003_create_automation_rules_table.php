<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('session_id')->index();
            $table->enum('rule_type', [
                'welcome_message',
                'out_of_office',
                'lead_qualifier',
                'follow_up_reminder',
                'follow_up_agent',
                'keyword_trigger',
                'inactivity_trigger',
            ]);
            $table->string('name');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->json('keywords')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->time('schedule_start')->nullable();
            $table->time('schedule_end')->nullable();
            $table->json('schedule_days')->nullable();
            $table->integer('delay_hours')->nullable();
            $table->integer('inactivity_hours')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
