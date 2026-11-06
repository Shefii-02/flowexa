<?php

namespace App\Console\Commands;

use App\Models\InstagramAccount;
use App\Modules\Instagram\Auth\InstagramAuthProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshInstagramTokens extends Command
{
    protected $signature = 'instagram:refresh-tokens';
    protected $description = 'Refresh Instagram Login long-lived tokens expiring in under 10 days';

    public function handle(InstagramAuthProvider $auth): int
    {
        InstagramAccount::where('auth_type', 'instagram_login')->where('status', 'active')
            ->where('token_expires_at', '<', now()->addDays(10))
            ->each(function (InstagramAccount $a) use ($auth) {
                try {
                    [$token, $expires] = $auth->refresh($a);
                    $a->update(['access_token' => $token, 'token_expires_at' => $expires, 'token_refreshed_at' => now()]);
                } catch (\Throwable $e) {
                    $a->update(['status' => 'token_expired']);
                    $this->notifyAdmins($a);
                    Log::warning("Instagram token refresh failed for account {$a->id}: " . $e->getMessage());
                }
            });
        return self::SUCCESS;
    }

    /** Push the workspace owners/admins: the connection needs to be re-authorised. */
    private function notifyAdmins(InstagramAccount $a): void
    {
        try {
            $push = app(\App\Services\FirebasePushService::class);
            \App\Models\User::where('company_id', $a->company_id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin']))->pluck('id')
                ->each(fn ($id) => $push->sendToUser((int) $id, 'instagram', 'Instagram connection expired',
                    "Reconnect @{$a->username} to resume your AutoDMs.", ['url' => '/instagram/autodm']));
        } catch (\Throwable $e) {
            Log::warning('Instagram token-expiry notification failed: ' . $e->getMessage());
        }
    }
}
