<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Older leads were created before `source` was recorded, so the Leads list showed nothing for them.
// Recover it from the lead's assignment record (the assignment stored the channel) — leads with no assignment stay blank ("Unknown" in the UI).
return new class extends Migration {
    private const FROM_ASSIGNMENT = [
        'wa_chat' => 'wa_chat', 'website_widget' => 'website', 'instagram' => 'instagram', 'whatsapp' => 'whatsapp',
        'meta_api' => 'whatsapp_cloud', 'flow_builder' => 'flow', 'campaign' => 'campaign', 'manual' => 'manual',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('leads') || !Schema::hasTable('lead_assignments') || !Schema::hasColumn('lead_assignments', 'lead_id')) { return; }
        foreach (self::FROM_ASSIGNMENT as $assignmentType => $source) {
            DB::table('leads')
                ->where(fn ($q) => $q->whereNull('source')->orWhere('source', ''))
                ->whereIn('id', DB::table('lead_assignments')->where('source_type', $assignmentType)->whereNotNull('lead_id')->select('lead_id'))
                ->update(['source' => $source]);
        }
    }

    public function down(): void {}
};
