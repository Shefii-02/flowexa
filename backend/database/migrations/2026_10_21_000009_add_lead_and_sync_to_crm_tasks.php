<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable()->after('deal_id')->index();   // lead-based follow-up (or none)
            $table->unsignedBigInteger('synced_task_id')->nullable()->after('lead_id');    // the paired follow-up ⇄ to-do copy
        });
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropColumn(['lead_id', 'synced_task_id']);
        });
    }
};
