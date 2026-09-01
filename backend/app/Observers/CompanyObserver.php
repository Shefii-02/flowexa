<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\CompanySetupService;

class CompanyObserver
{
    public function created(Company $company): void
    {
        app(CompanySetupService::class)->setup($company);
    }
}
