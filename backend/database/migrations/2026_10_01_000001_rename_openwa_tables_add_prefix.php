<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $renames = [
        'waha_sessions' => 'openwa_sessions',
        'waha_webhooks' => 'openwa_webhooks',
        'waha_message_logs' => 'openwa_message_logs',
        'message_sender_jobs' => 'openwa_message_sender_jobs',
        'wa_chat_templates' => 'openwa_templates',
        'wa_otp_services' => 'openwa_otp_services',
        'wa_otp_codes' => 'openwa_otp_codes',
        'wa_otp_logs' => 'openwa_otp_logs',
        'wa_export_jobs' => 'openwa_export_jobs',
        'wa_api_configs' => 'openwa_api_configs',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->renames) as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
