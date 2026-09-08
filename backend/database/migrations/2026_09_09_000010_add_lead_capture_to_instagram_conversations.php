<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_conversations', function (Blueprint $t) {
            // Qualification fields the AI agent has gathered in this DM thread.
            $t->json('collected')->nullable()->after('participant_username');
            // The CRM lead created once a phone number was captured.
            $t->foreignId('lead_id')->nullable()->after('contact_id')->constrained('leads')->nullOnDelete();
            $t->timestamp('lead_created_at')->nullable()->after('lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_conversations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lead_id');
            $t->dropColumn(['collected', 'lead_created_at']);
        });
    }
};
