<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //

        Schema::create('flow_builders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(false);
            $t->string('trigger_type', 30)->default('default');
            // default | keyword | season | campaign
            $t->json('trigger_keywords')->nullable();  // ['hi','hello','hola']
            $t->timestamp('active_from')->nullable();  // season start
            $t->timestamp('active_until')->nullable(); // season end
            $t->unsignedInteger('total_sessions')->default(0);
            $t->unsignedInteger('total_leads')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'is_active']);
        });

        Schema::table('flow_nodes', function (Blueprint $t) {
            $t->foreignId('flow_builder_id')->nullable()->constrained('flow_builders')->cascadeOnDelete()->after('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('flow_builders');

        Schema::table('flow_nodes', function (Blueprint $t) {
            $t->dropForeign(['flow_builder_id']);
            $t->dropColumn('flow_builder_id');
        });
    }
};
