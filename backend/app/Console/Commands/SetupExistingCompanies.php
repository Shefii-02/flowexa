<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanySetupService;
use Illuminate\Console\Command;

class SetupExistingCompanies extends Command
{
    protected $signature   = 'companies:setup-existing {--roles-only : Only (re)sync default roles, skip automations}';
    protected $description  = 'Create or refresh default roles (and automations) for every company. Idempotent.';

    public function handle(CompanySetupService $setup): int
    {
        $companies = Company::all();
        $this->info("Found {$companies->count()} companies.");

        foreach ($companies as $company) {
            $this->line("  ⚙  [{$company->id}] {$company->name}");

            try {
                if ($this->option('roles-only')) {
                    $setup->syncDefaultRoles($company);
                } else {
                    $setup->setup($company);
                }
                $this->info('  ✅ Done.');
            } catch (\Throwable $e) {
                $this->error("  ❌ Failed: {$e->getMessage()}");
            }
        }

        $this->info('All companies processed.');
        return self::SUCCESS;
    }
}
