<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Support\ChartOfAccounts;
use App\Modules\Accounts\Support\FiscalYearService;
use Illuminate\Validation\ValidationException;

class FiscalYearTest extends AccountsTestCase
{
    private function fy(): FiscalYearService { return app(FiscalYearService::class); }

    public function test_chart_is_seeded_with_codes_and_is_idempotent(): void
    {
        $this->assertSame('1010', AccLedger::where('system_key', 'cash')->value('code'));
        $this->assertSame('3200', AccLedger::where('system_key', 'retained')->value('code'));
        $this->assertSame('1690', AccLedger::where('system_key', 'accum_depreciation')->value('code'));
        $n = AccLedger::count();
        $this->svc->settings(self::CID)->update(['chart_version' => 0]);
        ChartOfAccounts::ensure(self::CID);
        $this->assertSame($n, AccLedger::count(), 'a second run must not duplicate ledgers');
    }

    public function test_legacy_ledgers_are_adopted_not_duplicated(): void
    {
        AccLedger::where('company_id', self::CID)->delete();
        AccLedger::create(['company_id' => self::CID, 'name' => 'Retained Earnings', 'nature' => 'equity', 'group' => 'capital']);
        AccLedger::create(['company_id' => self::CID, 'name' => 'My Custom Expense', 'nature' => 'expense', 'group' => 'indirect_expense']);
        $this->svc->settings(self::CID)->update(['chart_version' => 0]);
        ChartOfAccounts::ensure(self::CID);
        $this->assertSame(1, AccLedger::where('name', 'Retained Earnings')->count());
        $this->assertSame('retained', AccLedger::where('name', 'Retained Earnings')->value('system_key'));
        $this->assertNotNull(AccLedger::where('name', 'My Custom Expense')->value('code'));
    }

    public function test_party_ledgers_are_numbered_in_their_range(): void
    {
        $this->assertSame('1201', ChartOfAccounts::nextCode(self::CID, 'asset', 'debtor'));
        AccLedger::create(['company_id' => self::CID, 'name' => 'A', 'nature' => 'asset', 'group' => 'debtor', 'code' => '1201']);
        $this->assertSame('1202', ChartOfAccounts::nextCode(self::CID, 'asset', 'debtor'));
        $this->assertSame('2101', ChartOfAccounts::nextCode(self::CID, 'liability', 'creditor'));
        $this->assertSame('5700', ChartOfAccounts::nextCode(self::CID, 'expense', 'indirect_expense'));
    }

    public function test_profit_and_loss_follows_gross_operating_net(): void
    {
        $c = $this->party('Cust');
        $this->book(['type' => 'sales', 'date' => '2026-05-01', 'party_ledger_id' => $c->id, 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]]]);
        $this->book(['type' => 'purchase', 'date' => '2026-05-02', 'party_ledger_id' => $this->party('Sup', 'creditor')->id, 'items' => [['description' => 'y', 'qty' => 1, 'rate' => 400, 'gst_rate' => 0]]]);
        $this->book(['type' => 'expense', 'date' => '2026-05-03', 'ledger_id' => AccLedger::where('name', 'Office Rent')->value('id'), 'account_ledger_id' => $this->sys('cash'), 'amount' => 100]);
        $this->book(['type' => 'journal', 'date' => '2026-05-04', 'entries' => [['ledger_id' => $this->sys('interest_expense'), 'debit' => 50], ['ledger_id' => $this->sys('cash'), 'credit' => 50]]]);
        $this->book(['type' => 'journal', 'date' => '2026-05-05', 'entries' => [['ledger_id' => $this->sys('cash'), 'debit' => 20], ['ledger_id' => $this->sys('interest_income'), 'credit' => 20]]]);

        $pl = $this->svc->profitLoss(self::CID, '2026-04-01', '2027-03-31');
        $this->assertSame(600.0, $pl['gross_profit']);      // 1000 − 400
        $this->assertSame(500.0, $pl['operating_profit']);  // − rent 100
        $this->assertSame(-30.0, $pl['non_operating_net']); // +20 − 50
        $this->assertSame(470.0, $pl['net_profit']);
        $this->assertSame($pl['total_income'] - $pl['total_expense'], $pl['net_profit']);
    }

    public function test_balance_sheet_balances_and_has_sections(): void
    {
        $this->book(['type' => 'journal', 'date' => '2026-05-01', 'entries' => [['ledger_id' => $this->sys('bank'), 'debit' => 5000], ['ledger_id' => $this->sys('capital'), 'credit' => 5000]]]);
        $this->book(['type' => 'journal', 'date' => '2026-05-02', 'entries' => [['ledger_id' => $this->sys('vehicles'), 'debit' => 2000], ['ledger_id' => $this->sys('lt_loan'), 'credit' => 2000]]]);
        $bs = $this->svc->balanceSheet(self::CID, '2026-12-31');
        $this->assertTrue($bs['balanced']);
        $this->assertSame(5000.0, $bs['subtotals']['current_asset']);
        $this->assertSame(2000.0, $bs['subtotals']['fixed_asset']);
        $this->assertSame(2000.0, $bs['subtotals']['long_term_liability']);
        $this->assertSame(5000.0, $bs['subtotals']['equity']);
    }

    private function earnSomething(): void
    {
        $this->book(['type' => 'income', 'date' => '2026-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 900]);
        $this->book(['type' => 'expense', 'date' => '2026-07-10', 'ledger_id' => AccLedger::where('name', 'Office Rent')->value('id'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 300]);
    }

    public function test_closing_moves_profit_to_retained_earnings_and_locks_the_books(): void
    {
        $this->travelTo(now()->setDate(2027, 5, 1));
        $this->earnSomething();
        $this->fy()->ensure(self::CID);
        $year = \App\Modules\Accounts\Models\AccFiscalYear::where('start_date', '2026-04-01')->firstOrFail();

        $closed = $this->fy()->close(self::CID, null, $year->id);
        $this->assertSame('closed', $closed->status);
        $this->assertSame(600.0, $closed->net_profit);
        $this->assertSame(-600.0, $this->bal('retained'));                         // credit 600
        $this->assertEqualsWithDelta(0.0, $this->bal('other_income'), 0.001);      // income account zeroed
        $this->assertSame('2027-03-31', $this->svc->settings(self::CID)->locked_until->toDateString());
        $this->assertBooksBalance();

        // The closed year still reports its own result, and the balance sheet still tallies.
        $this->assertSame(600.0, $this->svc->profitLoss(self::CID, '2026-04-01', '2027-03-31')['net_profit']);
        $this->assertTrue($this->svc->balanceSheet(self::CID, '2027-03-31')['balanced']);

        // Anything dated inside the closed year is refused.
        $this->expectException(ValidationException::class);
        $this->book(['type' => 'income', 'date' => '2026-08-01', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 10]);
    }

    public function test_cannot_edit_or_delete_inside_closed_year(): void
    {
        $this->travelTo(now()->setDate(2027, 5, 1));
        $v = $this->book(['type' => 'income', 'date' => '2026-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 900]);
        $this->fy()->ensure(self::CID);
        $this->fy()->close(self::CID, null, \App\Modules\Accounts\Models\AccFiscalYear::where('start_date', '2026-04-01')->value('id'));
        $this->expectException(ValidationException::class);
        $this->svc->deleteVoucher(self::CID, $v);
    }

    public function test_years_close_in_order_and_cannot_close_before_they_end(): void
    {
        $this->travelTo(now()->setDate(2027, 9, 1));
        $this->book(['type' => 'income', 'date' => '2026-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 100]);
        $this->book(['type' => 'income', 'date' => '2027-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 100]);
        $this->fy()->ensure(self::CID);
        $ids = \App\Modules\Accounts\Models\AccFiscalYear::orderBy('start_date')->pluck('id', 'name');

        try { $this->fy()->close(self::CID, null, $ids['FY 2027-28']); $this->fail('should be blocked'); }
        catch (ValidationException $e) { $this->assertStringContainsString('FY 2026-27', $e->getMessage()); }

        $this->fy()->close(self::CID, null, $ids['FY 2026-27']);
        try { $this->fy()->close(self::CID, null, $ids['FY 2027-28']); $this->fail('current year has not ended'); }
        catch (ValidationException $e) { $this->assertStringContainsString('not ended', $e->getMessage()); }
    }

    public function test_reopen_removes_closing_entry_and_unlocks(): void
    {
        $this->travelTo(now()->setDate(2027, 5, 1));
        $this->earnSomething();
        $this->fy()->ensure(self::CID);
        $id = \App\Modules\Accounts\Models\AccFiscalYear::where('start_date', '2026-04-01')->value('id');
        $this->fy()->close(self::CID, null, $id);
        $this->fy()->reopen(self::CID, $id);
        $this->assertNull($this->svc->settings(self::CID)->fresh()->locked_until);
        $this->assertEqualsWithDelta(0.0, $this->bal('retained'), 0.001);
        $this->assertEqualsWithDelta(-900.0, $this->bal('other_income'), 0.001);
        $this->book(['type' => 'income', 'date' => '2026-08-01', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 10]);
        $this->assertBooksBalance();
    }

    public function test_only_latest_closed_year_can_be_reopened(): void
    {
        $this->travelTo(now()->setDate(2028, 9, 1));
        $this->book(['type' => 'income', 'date' => '2026-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 100]);
        $this->fy()->ensure(self::CID);
        $ids = \App\Modules\Accounts\Models\AccFiscalYear::orderBy('start_date')->pluck('id', 'name');
        $this->fy()->close(self::CID, null, $ids['FY 2026-27']);
        $this->fy()->close(self::CID, null, $ids['FY 2027-28']);
        $this->expectException(ValidationException::class);
        $this->fy()->reopen(self::CID, $ids['FY 2026-27']);
    }

    public function test_comparison_lines_up_two_periods(): void
    {
        $this->book(['type' => 'income', 'date' => '2025-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 400]);
        $this->book(['type' => 'income', 'date' => '2026-06-10', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 600]);
        $c = $this->svc->comparison(self::CID, '2026-04-01', '2027-03-31');
        $this->assertSame('2025-04-01', $c['previous']['from']);
        $this->assertSame(600.0, $c['income'][0]['current']);
        $this->assertSame(400.0, $c['income'][0]['previous']);
        $this->assertSame(50.0, $c['income'][0]['change_pct']);
        $this->assertSame(200.0, $c['totals']['net_profit']['change']);
    }
}
