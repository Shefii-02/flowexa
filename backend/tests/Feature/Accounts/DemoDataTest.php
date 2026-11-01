<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Support\DemoData;
use App\Modules\Accounts\Support\PaymentService;

class DemoDataTest extends AccountsTestCase
{
    private function demo(): DemoData { return app(DemoData::class); }

    private function assertHealthy(): void
    {
        $this->assertBooksBalance();
        $bs = $this->svc->balanceSheet(self::CID, now()->toDateString());
        $this->assertTrue($bs['balanced'], 'balance sheet must balance, off by ' . $bs['difference']);
        $this->assertEqualsWithDelta(0, app(\App\Modules\Accounts\Support\AssetService::class)->register(self::CID)['ledger_check']['difference'], 0.01, 'asset register must agree with the ledger');
        foreach ($this->svc->balances(self::CID) as $b) {
            if ($b['ledger']->system_key === 'petty_cash') { $this->assertGreaterThanOrEqual(0, $b['balance']); }
        }
        // every invoice's settled amount must be explained by allocations that never exceed its total
        foreach (['receivable', 'payable'] as $k) {
            foreach (app(PaymentService::class)->documents(self::CID, $k)['rows'] as $r) { $this->assertGreaterThanOrEqual(-0.005, $r['outstanding']); $this->assertLessThanOrEqual($r['total'] + 0.005, $r['settled']); }
        }
    }

    private function seedAt(string $date): array
    {
        $this->travelTo(\Carbon\Carbon::parse($date));
        return $this->demo()->seed(self::CID);
    }

    public function test_covers_every_part_and_keeps_the_books_sound(): void
    {
        $n = $this->seedAt('2026-11-01');
        foreach (['invoices', 'bills', 'receipts', 'payments', 'credit_notes', 'debit_notes', 'tds_bills', 'expenses', 'journals', 'income', 'contra', 'advances_applied', 'quotations', 'quotations_converted',
                  'assets', 'asset_disposals', 'depreciation_runs', 'adjusting', 'petty_topups', 'petty_spends', 'payroll_runs', 'settlements', 'employees', 'fiscal_years_closed', 'bank_entries_reconciled'] as $k) {
            $this->assertGreaterThan(0, $n[$k] ?? 0, "demo data is missing: {$k}");
        }
        $this->assertHealthy();

        $pay = app(PaymentService::class);
        $st = collect($pay->documents(self::CID, 'receivable')['rows'])->pluck('status')->unique()->all();
        foreach (['paid', 'partial', 'overdue', 'open'] as $s) { $this->assertContains($s, $st, "receivables should include a '{$s}' invoice"); }
        $this->assertGreaterThan(0, $pay->ageing(self::CID, 'receivable')['totals']['d90p'], 'one invoice should be 90+ days late');
        $this->assertNotEmpty($pay->documents(self::CID, 'payable', ['status' => 'overdue'])['rows']);

        $credit = collect($this->svc->creditOverview(self::CID)['rows'])->pluck('status')->all();
        $this->assertContains('over', $credit); $this->assertContains('hold', $credit);

        $q = collect(\App\Modules\Accounts\Models\AccQuotation::all())->pluck('effective_status')->unique()->sort()->values()->all();
        $this->assertSame(['accepted', 'draft', 'expired', 'invoiced', 'rejected', 'sent'], $q);

        $runs = \App\Modules\Accounts\Models\AccPayrollRun::orderBy('period')->pluck('status')->all();
        $this->assertSame(['paid', 'paid', 'paid', 'posted'], $runs);
        $this->assertSame(1, \App\Modules\Accounts\Models\AccEmployee::where('status', 'left')->count());

        $years = \App\Modules\Accounts\Models\AccFiscalYear::orderBy('start_date')->pluck('status')->all();
        $this->assertContains('closed', $years); $this->assertContains('open', $years);
        $this->assertSame(0.0, $this->bal('accrued_expenses'), 'the accrual should have auto-reversed');
        $pl = $this->svc->profitLoss(self::CID, '2026-04-01', '2027-03-31');
        $this->assertGreaterThan(0, $pl['revenue']); $this->assertNotEquals(0.0, $pl['non_operating_net']);
        $this->assertGreaterThan(0, $this->svc->dashboard(self::CID, '2026-04-01', '2027-03-31')['receivable']);
    }

    public function test_early_in_the_year_everything_still_works(): void
    {
        $this->seedAt('2026-04-06');      // today only just inside the new year: most "current year" dates clamp to today
        $this->assertHealthy();
    }

    public function test_mid_year_and_year_end_edges(): void
    {
        foreach (['2027-03-31', '2027-07-15'] as $i => $date) {
            if ($i) { $this->tearDown(); $this->setUp(); }
            $this->seedAt($date);
            $this->assertHealthy();
        }
    }

    public function test_cannot_load_twice_and_removal_restores_everything(): void
    {
        $this->book(['type' => 'journal', 'date' => '2025-01-01', 'entries' => [['ledger_id' => $this->sys('bank'), 'debit' => 1], ['ledger_id' => $this->sys('capital'), 'credit' => 1]]]);
        // a company with real entries must not be seeded over
        try { $this->demo()->seed(self::CID); $this->fail('seeding over real data'); } catch (\RuntimeException) {}
        \DB::table('acc_entries')->delete(); \DB::table('acc_vouchers')->delete();

        $before = ['ledgers' => AccLedger::count(), 'settings' => $this->svc->settings(self::CID)->only(['legal_name', 'petty_cash_limit', 'gstin'])];
        $this->seedAt('2026-11-01');
        try { $this->demo()->seed(self::CID); $this->fail('twice'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already loaded', $e->getMessage()); }
        $this->assertTrue($this->demo()->hasDemo(self::CID));

        $this->demo()->remove(self::CID);
        foreach (['acc_entries', 'acc_vouchers', 'acc_voucher_items', 'acc_allocations', 'acc_quotations', 'acc_quotation_items', 'acc_assets', 'acc_asset_depreciations', 'acc_employees', 'acc_payroll_runs',
                  'acc_payroll_lines', 'acc_fiscal_years', 'acc_items'] as $t) { $this->assertSame(0, \DB::table($t)->count(), "{$t} should be empty after removal"); }
        $this->assertSame($before['ledgers'], AccLedger::count(), 'only demo ledgers are removed; the standard chart stays');
        $this->assertEquals($before['settings'], $this->svc->settings(self::CID)->only(['legal_name', 'petty_cash_limit', 'gstin']));
        $this->assertNull($this->svc->settings(self::CID)->locked_until);
        $this->assertFalse($this->demo()->hasDemo(self::CID));

        // and it can be loaded again from scratch
        $this->demo()->seed(self::CID);
        $this->assertHealthy();
    }

    public function test_removal_refuses_when_real_entries_were_added(): void
    {
        $this->seedAt('2026-11-01');
        $this->book(['type' => 'income', 'date' => '2026-10-30', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 77]);
        $count = \DB::table('acc_vouchers')->count();
        try { $this->demo()->remove(self::CID); $this->fail('must refuse'); } catch (\RuntimeException $e) { $this->assertStringContainsString('Real entries', $e->getMessage()); }
        $this->assertSame($count, \DB::table('acc_vouchers')->count(), 'nothing may be deleted when it refuses');
    }

    /** Every screen's data endpoint, against realistic data — a 500 anywhere fails this. */
    public function test_every_read_endpoint_and_report_works_on_demo_data(): void
    {
        $this->seedAt('2026-11-01');
        $this->withoutMiddleware();
        $u = new \App\Models\User(); $u->forceFill(['id' => 1, 'company_id' => self::CID]); $this->actingAs($u);
        $range = 'from=2026-04-01&to=2027-03-31';
        $bank = $this->sys('bank'); $any = \App\Modules\Accounts\Models\AccVoucher::where('type', 'sales')->first();
        $paths = ['settings', 'dashboard?' . $range, 'ledgers', 'items', 'vouchers?' . $range, "vouchers/{$any->id}", 'gst?' . $range, 'einvoice?' . $range, "einvoice?voucher_id={$any->id}",
            'quotations', 'quotations?status=expired', 'credit', 'payments/receivable', 'payments/payable?status=overdue', 'payments/receivable/ageing', 'payments/payable/ageing',
            'payments/receivable/advances', 'payments/report?' . $range, 'adjusting?' . $range, 'assets', 'assets/depreciation/preview', 'petty-cash?' . $range,
            'payroll/employees', 'payroll/runs', 'fiscal-years', "reports/ledger?ledger_id={$bank}&{$range}", "reports/bank-reconciliation?ledger_id={$bank}&{$range}"];
        foreach (['profit-loss', 'balance-sheet', 'trial-balance', 'comparison', 'day-book', 'tds', 'stock', 'receivables', 'payables', 'ageing-receivable', 'ageing-payable'] as $r) { $paths[] = "reports/{$r}?{$range}"; }
        foreach (\App\Modules\Accounts\Models\AccPayrollRun::pluck('id') as $id) { $paths[] = "payroll/runs/{$id}"; }
        foreach (\App\Modules\Accounts\Models\AccQuotation::pluck('id') as $id) { $paths[] = "quotations/{$id}"; }
        foreach (\App\Modules\Accounts\Models\AccFiscalYear::pluck('id') as $id) { $paths[] = "fiscal-years/{$id}/preview"; }
        foreach (\App\Modules\Accounts\Models\AccEmployee::pluck('id') as $id) { $paths[] = "payroll/employees/{$id}/settlement"; }
        foreach ($paths as $p) { $r = $this->getJson('/api/v1/accounts/' . $p); $this->assertLessThan(400, $r->baseResponse->getStatusCode(), "GET {$p} → {$r->baseResponse->getStatusCode()} " . substr($r->getContent(), 0, 300)); }
        // CSV exports
        foreach (['profit-loss', 'balance-sheet', 'trial-balance', 'receivables', 'tds'] as $r) { $this->get("/api/v1/accounts/reports/{$r}?{$range}&format=csv")->assertOk(); }
        $this->get("/api/v1/accounts/gst?{$range}&format=csv&part=hsn")->assertOk();
    }
}
