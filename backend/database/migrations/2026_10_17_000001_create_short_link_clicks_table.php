<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('short_link_id');
            $table->date('click_date');
            $table->string('ip', 45);
            $table->string('device', 20)->nullable();   // mobile / tablet / desktop / bot
            $table->string('referrer', 255)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // one counted click per IP per link per day
            $table->unique(['short_link_id', 'click_date', 'ip'], 'slc_link_day_ip_unique');
            $table->index(['short_link_id', 'click_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_clicks');
    }
};
