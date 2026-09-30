<?php

namespace App\Console\Commands;

use App\Modules\WaChat\Services\WaChatTokenService;
use Illuminate\Console\Command;

class WaChatAdminKey extends Command
{
    protected $signature = 'wa-chat:admin-key
        {--rotate : Mint a new gateway ADMIN key, persist it as WA_CHAT_ADMIN_KEY, and revoke the old one}
        {--keep-old : With --rotate, leave the previous admin key active on the gateway instead of revoking it}';

    protected $description = "Check or rotate the open-wa gateway's own admin key (WA_CHAT_ADMIN_KEY)";

    public function handle(WaChatTokenService $svc): int
    {
        $this->line('Gateway: ' . $svc->baseUrl() . '   (WA_CHAT_API_ORIGIN)');

        try {
            $currentKey = $svc->adminKey();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (! $this->option('rotate')) {
            try {
                $id = $svc->currentAdminKeyId();
            } catch (\Throwable $e) {
                $id = null;
                $this->warn('Could not reach the gateway to look up the key id: ' . $e->getMessage());
            }
            $this->info('Admin key set : yes (' . substr($currentKey, 0, 14) . '…)');
            $this->line('Gateway key id: ' . ($id ?? 'unknown — no gateway key matches this prefix'));
            $this->newLine();
            $this->comment('Run with --rotate to mint a new admin key and retire this one.');
            return self::SUCCESS;
        }

        if (! $this->confirm('This replaces WA_CHAT_ADMIN_KEY and revokes the current admin key on the gateway. Continue?', true)) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        try {
            $result = $svc->rotateAdminKey(! $this->option('keep-old'));
        } catch (\Throwable $e) {
            $this->error('Rotation failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('✅ New admin key minted and persisted to ' . $result['persisted']);
        $this->line('   New key id: ' . $result['new_key_id']);
        $this->line('   New key   : ' . $result['new_key']);
        if ($result['old_key_id']) {
            $status = $this->option('keep-old') ? 'left active (--keep-old)' : 'revoked';
            $this->line("   Old key id: {$result['old_key_id']} — {$status}");
        } else {
            $this->warn('   Could not identify the old key on the gateway to revoke it — check the gateway key list manually.');
        }

        $this->call('config:clear');
        $this->newLine();
        $this->comment('Config cache cleared. If this environment runs `config:cache` in production, re-run it once you\'ve confirmed the new key works.');

        return self::SUCCESS;
    }
}
