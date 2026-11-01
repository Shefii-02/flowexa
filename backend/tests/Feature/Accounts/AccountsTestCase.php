<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Support\AccountsService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Boots an in-memory SQLite with only the accounting tables, so the engine is tested without the rest of the app. */
abstract class AccountsTestCase extends TestCase
{
    protected const CID = 1;
    protected AccountsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $files = array_merge(
            glob(database_path('migrations/2026_10_20_00000[13456]_*.php')),
            array_filter(glob(database_path('migrations/2026_11_01_*.php')), fn ($f) => !str_contains($f, 'permission')),
        );
        sort($files);
        Artisan::call('migrate', ['--path' => array_map(fn ($f) => 'database/migrations/' . basename($f), $files), '--force' => true]);
        $this->svc = app(AccountsService::class);
        $this->svc->settings(self::CID)->update(['state_code' => '32', 'gst_registered' => true, 'fy_start_month' => 4]);
        $this->svc->seedDefaults(self::CID);
    }

    protected function sys(string $key): int { return $this->svc->sys(self::CID, $key); }

    protected function party(string $name, string $group = 'debtor', array $extra = []): AccLedger
    {
        return AccLedger::firstOrCreate(['company_id' => self::CID, 'name' => $name], ['nature' => $group === 'debtor' ? 'asset' : 'liability', 'group' => $group, 'state_code' => '32'] + $extra);
    }

    protected function book(array $d, $existing = null)
    {
        return $this->svc->saveVoucher(self::CID, null, $d, $existing);
    }

    /** Balance of a ledger by system key, debit positive. */
    protected function bal(string $key): float
    {
        return $this->svc->balances(self::CID)[$this->sys($key)]['balance'];
    }

    protected function assertBooksBalance(): void
    {
        $tb = $this->svc->trialBalance(self::CID, '2099-12-31');
        $this->assertEqualsWithDelta($tb['total_debit'], $tb['total_credit'], 0.005, 'trial balance must tally');
    }
}
