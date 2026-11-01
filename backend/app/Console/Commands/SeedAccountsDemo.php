<?php

namespace App\Console\Commands;

use App\Modules\Accounts\Support\DemoData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedAccountsDemo extends Command
{
    protected $signature = 'accounts:seed-demo {company : Company id} {--remove : Remove the demo data again}';
    protected $description = 'Fill a company\'s Accounts module with realistic sample data, or remove it again with --remove';

    public function handle(DemoData $demo): int
    {
        $cid = (int) $this->argument('company');
        if (!DB::table('companies')->where('id', $cid)->exists()) { $this->error("Company {$cid} not found."); return self::FAILURE; }
        try {
            if ($this->option('remove')) {
                if (!$demo->hasDemo($cid)) { $this->error('No demo data is loaded for this company.'); return self::FAILURE; }
                if (!$this->confirm("Remove the demo accounting data from company {$cid}? Only the sample records are deleted.", true)) { return self::FAILURE; }
                $this->info('Demo data removed:');
                foreach ($demo->remove($cid) as $t => $n) { $this->line(sprintf('  %-22s %d', $t, $n)); }
                return self::SUCCESS;
            }
            $summary = $demo->seed($cid);
        } catch (\RuntimeException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $this->info('Demo accounting data created (remove later with: php artisan accounts:seed-demo ' . $cid . ' --remove):');
        foreach ($summary as $k => $v) { $this->line(sprintf('  %-26s %d', str_replace('_', ' ', $k), $v)); }
        return self::SUCCESS;
    }
}
