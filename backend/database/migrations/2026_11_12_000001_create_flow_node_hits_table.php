<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row each time a customer reaches a flow node that has a lead category: who, which node, when, and
        // which lead it created or found. Powers the "Flow clicks" analytics on a lead category.
        Schema::create('flow_node_hits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('flow_builder_id')->nullable();
            $t->unsignedBigInteger('flow_node_id')->nullable();
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('lead_id')->nullable();      // the lead this click created, or the one that already existed
            $t->boolean('created_lead')->default(false);        // true = this click created the lead
            $t->string('category', 100);                         // category name at the time (leads point at categories by name)
            $t->string('node_title', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('contact_name')->nullable();
            $t->timestamp('clicked_at')->useCurrent();
            $t->timestamps();

            $t->index(['company_id', 'category', 'clicked_at']);
            $t->index(['company_id', 'flow_node_id']);
            $t->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_node_hits');
    }
};
