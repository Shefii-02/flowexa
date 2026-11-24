<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\StaffAvailability;
use Illuminate\Console\Command;

/** Puts each staff member's "open leads" counter back in line with the leads they really hold (see StaffAvailability::recountWorkload). */
class RecountStaffWorkload extends Command
{
    protected $signature   = 'leads:recount-workload {--company= : one company id (default: all)}';
    protected $description = 'Recalculate every staff member\'s open-lead count from their actual leads';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')] : Company::pluck('id')->all();
        foreach ($ids as $id) { StaffAvailability::recountWorkload($id); }
        $this->info('Recounted ' . count($ids) . ' compan' . (count($ids) === 1 ? 'y' : 'ies') . '.');
        return self::SUCCESS;
    }
}
