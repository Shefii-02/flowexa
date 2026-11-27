<?php

namespace App\Console\Commands;

use App\Models\PushToken;
use Illuminate\Console\Command;

/**
 * Drops push devices nobody has used for a while, so "Your registered devices" doesn't fill up with browsers that were closed without
 * logging out. A web browser re-registers on every visit and a phone on every app start, so last_used_at is a reliable "still in use" signal;
 * anything that comes back simply registers again. Rows are deactivated (not deleted) — Firebase keeps them out of sends from then on.
 */
class PrunePushTokens extends Command
{
    protected $signature = 'push:prune-stale {--web-days=30} {--mobile-days=60}';
    protected $description = 'Deactivate push tokens (web / phone) that have not been used for a while.';

    public function handle(): int
    {
        $web = PushToken::where('is_active', true)->where('device_type', 'web')
            ->where(fn ($q) => $q->where('last_used_at', '<', now()->subDays((int) $this->option('web-days')))->orWhereNull('last_used_at'))
            ->update(['is_active' => false]);
        $mobile = PushToken::where('is_active', true)->where('device_type', '!=', 'web')
            ->where(fn ($q) => $q->where('last_used_at', '<', now()->subDays((int) $this->option('mobile-days')))->orWhereNull('last_used_at'))
            ->update(['is_active' => false]);

        $this->info("Deactivated {$web} stale web and {$mobile} stale phone push token(s).");
        return self::SUCCESS;
    }
}
