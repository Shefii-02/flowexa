<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Services\CompanySetupService;
use Illuminate\Console\Command;

class SetupExistingCompanies extends Command
{
    protected $signature   = 'companies:setup-existing';
    protected $description = 'Create default roles and templates for companies that were created before CompanySetupService existed';

    public function handle(CompanySetupService $setup): int
    {
        $companies = Company::all();
        $this->info("Found {$companies->count()} companies.");

        foreach ($companies as $company) {
            $hasRoles = Role::where('company_id', $company->id)->exists();

            if ($hasRoles) {
                $this->line("  ⏭  [{$company->id}] {$company->name} — already has roles, skipping.");
                continue;
            }

            $this->info("  ⚙  [{$company->id}] {$company->name} — setting up...");

            try {
                $setup->setup($company);
                $this->info("  ✅ Done.");
            } catch (\Throwable $e) {
                $this->error("  ❌ Failed: {$e->getMessage()}");
            }
        }

        $this->info('All companies processed.');
        return self::SUCCESS;
    }
}
