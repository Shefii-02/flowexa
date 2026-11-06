<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The AutoDM wizard compiles its config into the flow graph; the raw config is kept for editing.
        Schema::table('instagram_flows', function (Blueprint $t) {
            $t->json('wizard')->nullable();
            $t->string('status', 10)->default('enabled');          // draft | enabled | paused (wizard flows)
            $t->unsignedInteger('dms_sent')->default(0);
            $t->unsignedInteger('leads')->default(0);
            $t->unsignedInteger('new_followers')->default(0);
        });

        // One row per tracked button link; /r/{token} bumps `clicks` and flags the run as "clicked".
        Schema::create('instagram_link_clicks', function (Blueprint $t) {
            $t->id();
            $t->string('token', 16)->unique();
            $t->foreignId('instagram_flow_id')->constrained('instagram_flows')->cascadeOnDelete();
            $t->unsignedBigInteger('instagram_flow_run_id')->index();
            $t->text('url');
            $t->unsignedInteger('clicks')->default(0);
            $t->timestamp('first_clicked_at')->nullable();
            $t->timestamp('last_clicked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_link_clicks');
        Schema::table('instagram_flows', fn (Blueprint $t) => $t->dropColumn(['wizard', 'status', 'dms_sent', 'leads', 'new_followers']));
    }
};
