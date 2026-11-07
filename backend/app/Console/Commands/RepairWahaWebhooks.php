<?php

namespace App\Console\Commands;

use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\WahaWebhookRegistrar;
use Illuminate\Console\Command;

class RepairWahaWebhooks extends Command
{
    protected $signature = 'wa-chat:repair-webhooks';
    protected $description = 'Re-register every WA Chat session webhook (fixes hooks saved with http:// or an old URL)';

    public function handle(WahaWebhookRegistrar $registrar): int
    {
        $this->line('Target: ' . WahaWebhookRegistrar::targetUrl());
        $ok = 0; $bad = 0;
        WahaSession::query()->each(function (WahaSession $s) use ($registrar, &$ok, &$bad) {
            $registrar->ensure($s) ? $ok++ : $bad++;
        });
        $this->info("Checked {$ok} session(s)" . ($bad ? ", {$bad} failed (see laravel.log)" : '.'));
        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
