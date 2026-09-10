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
        {--provision : Mint a fresh gateway key and store it}';

    protected $description = 'Check or (re)provision a company\'s open-wa gateway API key (Company.wa_chat_token)';

    public function handle(WaChatTokenService $svc): int
    {
        $companies = $this->resolveCompanies();
        if ($companies->isEmpty()) {
            $this->error('No matching company. Pass an id/slug or --all.');
            return self::FAILURE;
        }

        $provision = (bool) $this->option('provision');
        $this->line("Gateway: " . config('services.open_wa.base_url'));
        $this->newLine();

        $bad = 0;
        foreach ($companies as $company) {
            $label = "#{$company->id} {$company->name}";

            if ($provision) {
                try {
                    $token = $svc->provision($company);
                    $this->info("  ✅ {$label} — provisioned " . substr($token, 0, 14) . '…');
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
