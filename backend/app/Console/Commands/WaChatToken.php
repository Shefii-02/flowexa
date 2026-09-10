<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Modules\WaChat\Services\WaChatTokenService;
use Illuminate\Console\Command;

class WaChatToken extends Command
{
    protected $signature = 'wa-chat:token
        {company? : Company id or slug (omit with --all)}
        {--all : Operate on every company}
        {--check : Verify the stored token against the gateway (default)}
        {--provision : Mint a fresh scoped gateway key and store it}
        {--sync : Re-push the company\'s session allowlist to its gateway key}';

    protected $description = 'Check or (re)provision a company\'s open-wa gateway API key (Company.wa_chat_token)';

    public function handle(WaChatTokenService $svc): int
    {
        $companies = $this->resolveCompanies();
        if ($companies->isEmpty()) {
            $this->error('No matching company. Pass an id/slug or --all.');
            return self::FAILURE;
        }

        $provision = (bool) $this->option('provision');
        $base     = (string) config('services.open_wa.base_url');
        $adminSet = filled(config('services.open_wa.admin_key'));

        $this->line('Gateway      : ' . $base . '   (WA_CHAT_API_ORIGIN)');
        $this->line('Admin key set: ' . ($adminSet ? 'yes' : 'NO  — set WA_CHAT_ADMIN_KEY to --provision'));
        if (str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
            $this->warn('Gateway points at localhost. On a server, WA_CHAT_API_ORIGIN must be the');
            $this->warn('real open-wa origin (e.g. https://unichatwa.univexa.in) or the local port it listens on.');
        }
        $this->newLine();

        $sync = (bool) $this->option('sync');
        $bad  = 0;
        foreach ($companies as $company) {
            $label = "#{$company->id} {$company->name}";

            if ($provision) {
                try {
                    $token = $svc->provision($company);
                    $scope = count($svc->sessionScope($company));
                    $this->info("  ✅ {$label} — provisioned " . substr($token, 0, 14) . "… (scoped to {$scope} session/s)");
                } catch (\Throwable $e) {
                    $this->error("  ❌ {$label} — {$e->getMessage()}");
                    $bad++;
                }
                continue;
            }

            if ($sync) {
                try {
                    $svc->syncSessions($company);
                    $this->info("  ✅ {$label} — allowlist synced (" . count($svc->sessionScope($company)) . ' session/s)');
                } catch (\Throwable $e) {
                    $this->error("  ❌ {$label} — {$e->getMessage()}");
                    $bad++;
                }
                continue;
            }

            $s = $svc->status($company);
            if ($s['valid']) {
                $this->info("  ✅ {$label} — token valid");
            } else {
                $this->warn("  ⚠️  {$label} — {$s['reason']}");
                $bad++;
            }
        }

        $this->newLine();
        $this->line($bad === 0 ? 'All good.' : "{$bad} company(ies) need attention.");
        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return \Illuminate\Support\Collection<int, Company> */
    private function resolveCompanies()
    {
        if ($this->option('all')) {
            return Company::query()->orderBy('id')->get();
        }

        $key = $this->argument('company');
        if (! $key) {
            return collect();
        }

        return Company::query()
            ->where('id', is_numeric($key) ? (int) $key : 0)
            ->orWhere('slug', $key)
            ->get();
    }
}
