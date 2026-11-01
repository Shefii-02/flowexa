<?php

namespace Tests\Feature\Accounts;

use App\Models\User;
use App\Modules\Accounts\Models\AccLedger;

/** Drives the real routes → controllers → services, so wiring, validation rules and JSON shapes are covered too. */
class ApiSmokeTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $u = new User(); $u->forceFill(['id' => 1, 'company_id' => self::CID]);
        $this->actingAs($u);
    }

    private function api(string $method, string $uri, array $data = [], int $expect = 200)
    {
        $r = $this->json($method, '/api/v1/accounts/' . $uri, $data);
        $r->assertStatus($expect);
        return $r;
    }

    public function test_sales_workflow_end_to_end(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 3));
        $cust = $this->api('POST', 'ledgers', ['name' => 'Acme', 'nature' => 'asset', 'group' => 'debtor', 'state_code' => '32', 'credit_limit' => 5000, 'credit_days' => 15], 201)->json('ledger');
        $this->assertEquals('1201', $cust['code']);

        $q = $this->api('POST', 'quotations', ['date' => '2026-06-01', 'party_ledger_id' => $cust['id'], 'subject' => 'Setup',
            'items' => [['description' => 'Work', 'qty' => 2, 'rate' => 1000, 'gst_rate' => 18]]], 201)->json('quotation');
        $this->assertEquals(2360.0, $q['total']);
        $this->api('POST', "quotations/{$q['id']}/status", ['status' => 'accepted']);
        $this->assertEquals(1, $this->api('GET', 'quotations')->json('summary.counts.accepted'));

        $conv = $this->api('POST', "quotations/{$q['id']}/convert", ['date' => '2026-06-02'])->json();
        $inv = $conv['voucher'];
        $this->assertEquals('2026-06-17', substr($inv['due_date'], 0, 10));                      // 15-day terms

        $rcv = $this->api('GET', 'payments/receivable')->json();
        $this->assertEquals(2360.0, $rcv['total_outstanding']);

        $this->api('POST', 'vouchers', ['type' => 'receipt', 'date' => '2026-06-03', 'party_ledger_id' => $cust['id'], 'account_ledger_id' => $this->sys('bank'),
            'amount' => 1000, 'allocations' => [['voucher_id' => $inv['id'], 'amount' => 1000]]], 201);
        $this->assertEquals(1360.0, $this->api('GET', 'payments/receivable')->json('total_outstanding'));
        $this->assertEquals(1360.0, $this->api('GET', "vouchers/{$inv['id']}")->json('voucher.outstanding'));
        $this->api('GET', 'payments/receivable/ageing');
        $this->api('GET', 'reports/ageing-receivable?to=2026-07-30');
        $this->api('GET', 'payments/report?from=2026-06-01&to=2026-06-30');
        $this->assertEquals(1, $this->api('GET', 'credit')->json('rows.0.owing') > 0 ? 1 : 0);

        // deleting the paid invoice is refused with a readable 422
        $this->api('DELETE', "vouchers/{$inv['id']}", [], 422)->assertJsonValidationErrors('voucher');
        // credit limit: another 5000 invoice breaches 5000 limit → 422 keyed `credit_limit`
        $this->api('POST', 'vouchers', ['type' => 'sales', 'date' => '2026-06-20', 'party_ledger_id' => $cust['id'], 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 5000]]], 422)
            ->assertJsonValidationErrors('credit_limit');
    }

    public function test_advance_flow_over_http(): void
    {
        $c = $this->party('Adv');
        $adv = $this->api('POST', 'vouchers', ['type' => 'receipt', 'date' => '2026-06-01', 'party_ledger_id' => $c->id, 'account_ledger_id' => $this->sys('bank'), 'amount' => 300, 'is_advance' => true], 201)->json('voucher');
        $this->assertTrue($adv['is_advance']);
        $inv = $this->api('POST', 'vouchers', ['type' => 'sales', 'date' => '2026-06-05', 'party_ledger_id' => $c->id, 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 500]]], 201)->json('voucher');
        $this->assertEquals(300.0, $this->api('GET', 'payments/receivable/advances')->json('total'));
        $a = $this->api('POST', 'payments/apply-advance', ['advance_id' => $adv['id'], 'invoice_id' => $inv['id'], 'amount' => 300, 'date' => '2026-06-06'], 201)->json('allocation');
        $this->assertEquals(200.0, $this->api('GET', 'payments/receivable')->json('total_outstanding'));
        $this->api('DELETE', "payments/allocations/{$a['id']}");
        $this->assertEquals(500.0, $this->api('GET', 'payments/receivable')->json('total_outstanding'));
        // system journals can't be edited or deleted by hand
        $this->api('POST', 'payments/apply-advance', ['advance_id' => $adv['id'], 'invoice_id' => $inv['id'], 'amount' => 100, 'date' => '2026-06-06'], 201);
        $jid = \DB::table('acc_vouchers')->where('source_type', 'advance')->value('id');
        $this->api('DELETE', "vouchers/{$jid}", [], 422);
        $this->api('PUT', "vouchers/{$jid}", ['type' => 'journal', 'date' => '2026-06-06', 'entries' => []], 422);
    }

    public function test_assets_adjusting_petty_year_end_over_http(): void
    {
        $this->travelTo(now()->setDate(2027, 5, 1));
        $a = $this->api('POST', 'assets', ['name' => 'Van', 'category' => 'vehicle', 'purchase_date' => '2026-04-10', 'cost' => 12000, 'useful_life_months' => 12, 'paid_from_ledger_id' => $this->sys('bank')], 201)->json('asset');
        $this->assertGreaterThan(0, $this->api('GET', 'assets/depreciation/preview')->json('total'));
        $this->api('POST', 'assets/depreciation', []);
        $reg = $this->api('GET', 'assets')->json();
        $this->assertEquals(12000.0, $reg['totals']['accumulated']);
        $this->assertEqualsWithDelta(0, $reg['ledger_check']['difference'], 0.01);
        $this->api('POST', "assets/{$a['id']}/dispose", ['date' => '2027-04-20', 'proceeds' => 500, 'account_ledger_id' => $this->sys('bank')]);

        $rent = AccLedger::where('name', 'Office Rent')->value('id');
        $adj = $this->api('POST', 'adjusting', ['kind' => 'accrued_expense', 'date' => '2026-06-30', 'amount' => 90, 'ledger_id' => $rent, 'auto_reverse' => true], 201)->json('voucher');
        $this->assertEquals(1, \DB::table('acc_vouchers')->where('reverses_voucher_id', $adj['id'])->count() + 1 - 0 * 1, 'reversal posts lazily when the list is read');
        $list = $this->api('GET', 'adjusting?from=2026-04-01&to=2027-03-31')->json('rows');
        $this->assertNotEmpty($list);
        $this->assertEquals(1, \DB::table('acc_vouchers')->where('reverses_voucher_id', $adj['id'])->count());

        $this->api('PUT', 'settings', ['petty_cash_limit' => 400]);
        $this->api('POST', 'petty-cash/top-up', ['date' => '2026-07-01', 'from_ledger_id' => $this->sys('bank')], 201);
        $this->api('POST', 'petty-cash/spend', ['date' => '2026-07-02', 'ledger_id' => $rent, 'amount' => 50, 'description' => 'Tea'], 201);
        $this->assertEquals(350.0, $this->api('GET', 'petty-cash?from=2026-07-01&to=2026-07-31')->json('balance'));
        $this->api('POST', 'petty-cash/spend', ['date' => '2026-07-03', 'ledger_id' => $rent, 'amount' => 9999], 422);

        foreach (['profit-loss', 'balance-sheet', 'trial-balance', 'comparison', 'day-book'] as $r) { $this->api('GET', "reports/{$r}?from=2026-04-01&to=2027-03-31"); }
        $this->api('GET', 'dashboard?from=2026-04-01&to=2027-03-31');
        $this->api('GET', 'ledgers');

        $years = $this->api('GET', 'fiscal-years')->json('years');
        $fy = collect($years)->firstWhere('start_date', '2026-04-01');
        $this->assertTrue($this->api('GET', "fiscal-years/{$fy['id']}/preview")->json('can_close'));
        $this->api('POST', "fiscal-years/{$fy['id']}/close");
        $this->api('POST', 'vouchers', ['type' => 'income', 'date' => '2026-08-01', 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 5], 422)->assertJsonValidationErrors('date');
        $this->api('PUT', 'settings', ['fy_start_month' => 1], 422);
        $this->api('POST', "fiscal-years/{$fy['id']}/reopen");
        $this->api('PUT', 'settings', ['fy_start_month' => 1]);
        $this->assertTrue(collect($this->api('GET', 'fiscal-years')->json('years'))->every(fn ($y) => str_starts_with($y['name'], 'FY 20') && !str_contains($y['name'], '-')));
    }

    public function test_payroll_over_http(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 10));
        $e = $this->api('POST', 'payroll/employees', ['name' => 'Asha', 'joined_on' => '2025-01-01', 'basic' => 3000, 'allowance' => 500, 'annual_ticket' => 1200], 201)->json('employee');
        $run = $this->api('POST', 'payroll/runs', ['period' => '2026-02'], 201)->json('run');
        $line = $run['lines'][0];
        $this->api('PUT', "payroll/runs/{$run['id']}/lines/{$line['id']}", ['commission' => 200]);
        $this->api('POST', "payroll/runs/{$run['id']}/post");
        $this->api('POST', "payroll/runs/{$run['id']}/pay", ['account_ledger_id' => $this->sys('bank'), 'date' => '2026-02-28']);
        $this->assertEquals('paid', $this->api('GET', "payroll/runs/{$run['id']}")->json('run.status'));
        $this->assertEquals('2026-03', $this->api('GET', 'payroll/runs')->json('next_period'));
        $this->api('POST', 'payroll/runs', ['period' => '2026-04'], 422);
        $q = $this->api('GET', "payroll/employees/{$e['id']}/settlement?date=2026-02-28")->json();
        $this->assertArrayHasKey('due', $q);
        $this->api('GET', 'payroll/employees');
    }

    public function test_sample_data_can_be_loaded_and_removed_over_http(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->assertTrue($this->api('GET', 'demo')->json('can_load'));
        $this->api('POST', 'demo', [], 201);
        $st = $this->api('GET', 'demo')->json();
        $this->assertTrue($st['loaded']); $this->assertFalse($st['can_load']);
        $this->api('POST', 'demo', [], 422);
        $this->assertGreaterThan(50, \DB::table('acc_vouchers')->count());
        $this->api('DELETE', 'demo');
        $this->assertSame(0, \DB::table('acc_vouchers')->count());
        $this->assertTrue($this->api('GET', 'demo')->json('can_load'));
        $this->api('DELETE', 'demo', [], 422);
    }
}
