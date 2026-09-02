<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\LeadAssignment\AiStaffHandoffService;
use Illuminate\Console\Command;

class ProcessAiHandoffs extends Command
{
    protected $signature   = 'leads:process-handoffs';
    protected $description = 'Offer AI-handled leads to available staff';

    public function handle(AiStaffHandoffService $service): int
    {
        Company::all()->each(function (Company $company) use ($service) {
            $service->offerToAvailableStaff($company);
        });

        return self::SUCCESS;
    }
}
