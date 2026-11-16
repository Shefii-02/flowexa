<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA Cloud reply routing: what happens to an INCOMING customer message — hand it to a chatbot flow, an AI agent,
 * a sales-team member, an auto-reply, or nobody — per phone number or for all numbers, optionally by business hours.
 * Plus an event log so analytics can show where every conversation went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_cloud_reply_routes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('wa_phone_number_id')->nullable();   // null = every number
            $t->string('name', 120);
            $t->string('when_mode', 12)->default('always');             // always | in_hours | out_of_hours
            $t->json('schedule_days')->nullable();                       // ['monday', …]; empty = every day
            $t->string('schedule_start', 5)->nullable();                 // HH:MM office hours
            $t->string('schedule_end', 5)->nullable();
            $t->string('response_type', 12);                             // chatbot | ai_agent | handover | auto_reply | manual
            $t->unsignedBigInteger('flow_builder_id')->nullable();       // chatbot: which flow
            $t->unsignedBigInteger('playbook_id')->nullable();           // ai_agent: which AI agent
            $t->json('handover')->nullable();                            // {user_ids:[], strategy: round_robin|least_busy, ack_message}
            $t->text('reply_text')->nullable();                          // auto_reply body
            $t->string('fallback_type', 12)->default('none');            // legacy single-step routes only
            $t->json('steps')->nullable();                               // ordered responders: [{type, flow_builder_id, playbook_id, handover, reply_text, schedule}, …]
            $t->json('escalate_keywords')->nullable();                   // customer says one of these → jump to the next human step
            $t->unsignedSmallInteger('priority')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['company_id', 'wa_phone_number_id', 'is_active'], 'wcrr_scope');
        });

        Schema::create('wa_cloud_route_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('route_id')->nullable();
            $t->unsignedBigInteger('wa_phone_number_id')->nullable();
            $t->string('phone', 32);
            $t->string('response_type', 12);                             // what the route chose
            $t->string('final_type', 12)->nullable();                    // what actually answered (differs after a fallback)
            $t->unsignedTinyInteger('step')->nullable();                 // 1-based position in the chain that answered
            $t->string('outcome', 10);                                   // handled | fallback | skipped | failed
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('detail', 190)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at'], 'wcre_company_time');
            $t->index(['company_id', 'response_type'], 'wcre_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_route_events');
        Schema::dropIfExists('wa_cloud_reply_routes');
    }
};
