<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\Role;
use App\Services\CompanySetupService;
use Illuminate\Support\Facades\Log;

class CompanyObserver
{
    /** Names of the roles CompanySetupService creates for every company. */
    private const DEFAULT_ROLE_NAMES = ['Admin', 'Manager', 'Sales Agent', 'Support Agent', 'Viewer'];

    public function __construct(private readonly CompanySetupService $setup) {}

    /** New company → default roles + starter automations. */
    public function created(Company $company): void
    {
        $this->safely(fn () => $this->setup->setup($company), $company, 'created');
    }

    /** Existing company changed → self-heal any missing default roles. */
    public function updated(Company $company): void
    {
        $have = Role::where('company_id', $company->id)
            ->whereIn('name', self::DEFAULT_ROLE_NAMES)
            ->count();

        if ($have < count(self::DEFAULT_ROLE_NAMES)) {
            $this->safely(fn () => $this->setup->syncDefaultRoles($company), $company, 'updated');
        }
    }

    /** Soft-deleted company brought back → rebuild what the cascade removed. */
    public function restored(Company $company): void
    {
        $this->safely(fn () => $this->setup->setup($company), $company, 'restored');
    }

    /**
     * Role syncing must never block a company create/update. Log and move on.
     */
    private function safely(callable $fn, Company $company, string $event): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("CompanyObserver@{$event}: role sync failed for company {$company->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
