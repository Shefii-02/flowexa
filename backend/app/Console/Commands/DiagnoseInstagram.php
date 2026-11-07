<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** php artisan instagram:diagnose — checks the Instagram/Meta credentials without needing a real login. Never prints secrets. */
class DiagnoseInstagram extends Command
{
    protected $signature = 'instagram:diagnose';
    protected $description = 'Check Instagram Login / Meta app credentials and likely connect problems';

    public function handle(): int
    {
        $igId     = (string) config('services.instagram.app_id');
        $igSecret = (string) config('services.instagram.ig_app_secret');
        $metaId   = (string) config('services.messenger.app_id');
        $metaSec  = (string) config('services.messenger.app_secret');

        $this->line('Instagram app id : ' . ($igId ?: 'MISSING'));
        $this->line('IG_APP_SECRET    : ' . $this->mask($igSecret));
        $this->line('Meta app id      : ' . ($metaId ?: 'MISSING'));
        $this->line('Meta app secret  : ' . $this->mask($metaSec) . '  (META_APP_SECRET / META_ADS_APP_SECRET)');
        $this->line('Redirect URI     : ' . config('services.instagram.redirect_uri'));
        $this->line('Graph version    : ' . config('services.instagram.ig_graph_version'));
        $this->newLine();

        $ok = true;
        if (!$igSecret) { $this->error('✗ IG_APP_SECRET is not set.'); $ok = false; }
        if ($igSecret && $metaSec && hash_equals($igSecret, $metaSec)) {
            $this->error('✗ IG_APP_SECRET is identical to the main Meta app secret. They are two different secrets: '
                . 'IG_APP_SECRET must be the "Instagram app secret" (App → Instagram → API setup with Instagram login).');
            $ok = false;
        }

        // 1) Is the Instagram app secret accepted? A bogus code distinguishes "bad secret" from "bad code".
        if ($igId && $igSecret) {
            $r = Http::asForm()->timeout(20)->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => $igId, 'client_secret' => $igSecret, 'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.instagram.redirect_uri'), 'code' => 'diagnostic-not-a-real-code',
            ]);
            $msg = mb_strtolower((string) ($r->json('error_message') ?? $r->json('error.message') ?? ''));
            if (str_contains($msg, 'secret')) {
                $this->error("✗ Instagram rejected IG_APP_SECRET ({$msg}).");
                $ok = false;
            } elseif (str_contains($msg, 'redirect')) {
                $this->error("✗ Instagram rejected the redirect URI ({$msg}). It must match the Meta dashboard exactly.");
                $ok = false;
            } elseif (str_contains($msg, 'app id') || str_contains($msg, 'client id') || str_contains($msg, 'client_id')) {
                $this->error("✗ Instagram rejected the app id ({$msg}).");
                $ok = false;
            } else {
                $this->info("✓ Instagram accepted the app id, secret and redirect URI (it only complained about the fake code: \"{$msg}\").");
            }
        }

        // 2) Main Meta app secret (used to verify webhook signatures and Messenger).
        if ($metaId && $metaSec) {
            $r = Http::timeout(20)->get('https://graph.facebook.com/oauth/access_token', [
                'client_id' => $metaId, 'client_secret' => $metaSec, 'grant_type' => 'client_credentials',
            ]);
            if ($r->successful() && $r->json('access_token')) $this->info('✓ Main Meta app secret is valid.');
            else { $this->error('✗ Main Meta app secret was rejected: ' . ($r->json('error.message') ?? 'HTTP ' . $r->status())); $ok = false; }
        } else {
            $this->warn('! META_APP_SECRET is not set — Instagram webhook signatures signed with the main app secret will be rejected.');
        }

        $this->newLine();
        $this->line('If the secrets above are fine and connecting still fails with "Unsupported request … during long-lived token", check:');
        $this->line(' • the Instagram account is a Business or Creator account, and');
        $this->line(' • it is added under App roles → Roles → Instagram Testers AND the invite was accepted (Instagram → Settings → Apps and websites → Tester invites).');
        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function mask(string $s): string
    {
        return $s === '' ? 'NOT SET' : 'set, ' . strlen($s) . ' chars, ends …' . substr($s, -4);
    }
}
