<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccEmployee;
use App\Modules\Accounts\Support\PayrollService;
use App\Modules\Accounts\Support\PettyCashService;
use Illuminate\Validation\ValidationException;

class PayrollAndPettyCashTest extends AccountsTestCase
{
    private function pr(): PayrollService { return app(PayrollService::class); }
    private function petty(): PettyCashService { return app(PettyCashService::class); }

    private function emp(array $x = []): AccEmployee
    {
        return $this->pr()->saveEmployee(self::CID, $x + ['name' => 'Asha', 'joined_on' => '2025-01-01', 'basic' => 3000, 'allowance' => 600, 'annual_ticket' => 1200,
            'leave_days_per_year' => 30, 'eos_days_per_year' => 21]);
    }

    public function test_monthly_payroll_accrues_salary_and_provisions_then_pays(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 10));
        $this->emp();
        $run = $this->pr()->createRun(self::CID, null, '2026-01');
        $this->assertSame('draft', $run->status);
        $this->assertSame(3600.0, $run->total_net);

        $this->pr()->post(self::CID, null, $run->id);
        $this->assertSame(3600.0, $this->bal('salaries') - 0.0);
        $this->assertSame(-3600.0, $this->bal('salary_payable'));
        // monthly provisions: leave 3000/30*30/12 = 250 · ticket 1200/12 = 100 · EOS 3000/30*21/12 = 175
        $this->assertSame(-250.0, $this->bal('leave_provision'));
        $this->assertSame(-100.0, $this->bal('ticket_provision'));
        $this->assertSame(-175.0, $this->bal('eos_provision'));
        $this->assertSame(525.0, $this->bal('leave_expense') + $this->bal('ticket_expense') + $this->bal('eos_expense'));
        $this->assertBooksBalance();

        $this->pr()->pay(self::CID, null, $run->id, $this->sys('bank'), '2026-01-31');
        $this->assertSame(0.0, $this->bal('salary_payable'));
        $this->assertSame(-3600.0, $this->bal('bank'));
        $this->assertSame('paid', $this->pr()->show(self::CID, $run->id)->status);
        try { $this->pr()->pay(self::CID, null, $run->id, $this->sys('bank')); $this->fail('twice'); } catch (ValidationException) {}
        $this->assertBooksBalance();
    }

    public function test_commission_unpaid_days_and_deductions(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 10));
        $this->emp();
        $run = $this->pr()->createRun(self::CID, null, '2026-01');
        $line = $run->lines->first();
        $run = $this->pr()->updateLine(self::CID, $run->id, $line->id, ['commission' => 500, 'unpaid_days' => 3, 'other_deductions' => 100]);
        $l = $run->lines->first();
        $this->assertSame(360.0, $l->unpaid_deduction);               // 3600/30 × 3
        $this->assertSame(3640.0, $l->net);                           // 3600 + 500 − 360 − 100
        $this->pr()->post(self::CID, null, $run->id);
        $this->assertSame(3240.0 + 500.0 - 500.0, $this->bal('salaries'));      // wages net of unpaid leave
        $this->assertSame(500.0, $this->bal('commission'));
        $this->assertSame(-100.0, $this->bal('payroll_deductions'));
        $this->assertSame(-3640.0, $this->bal('salary_payable'));
        $this->assertBooksBalance();
    }

    public function test_part_month_is_prorated(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 10));
        $this->emp(['joined_on' => '2026-01-16']);                    // 16 of 31 days
        $run = $this->pr()->createRun(self::CID, null, '2026-01');
        $this->assertEqualsWithDelta(round(3600 * 16 / 31, 2), $run->total_net, 0.01);
    }

    public function test_months_run_in_order_with_no_gaps(): void
    {
        $this->travelTo(now()->setDate(2026, 4, 10));
        $this->emp();
        $jan = $this->pr()->createRun(self::CID, null, '2026-01');
        try { $this->pr()->createRun(self::CID, null, '2026-02'); $this->fail('january is still draft'); } catch (ValidationException) {}
        $this->pr()->post(self::CID, null, $jan->id);
        try { $this->pr()->createRun(self::CID, null, '2026-03'); $this->fail('february skipped'); } catch (ValidationException $e) { $this->assertStringContainsString('2026-02', $e->getMessage()); }
        try { $this->pr()->createRun(self::CID, null, '2026-05'); $this->fail('future'); } catch (ValidationException) {}
        $feb = $this->pr()->createRun(self::CID, null, '2026-02');
        try { $this->pr()->unpost(self::CID, $jan->id); $this->fail('not the latest'); } catch (ValidationException) {}
        $this->pr()->deleteRun(self::CID, $feb->id);
        $this->pr()->unpost(self::CID, $jan->id);
        $this->assertSame(0.0, $this->bal('salary_payable'));
        $this->assertBooksBalance();
    }

    public function test_posted_run_cannot_be_edited_and_paid_run_cannot_be_unposted(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 10));
        $this->emp();
        $run = $this->pr()->createRun(self::CID, null, '2026-01');
        $this->pr()->post(self::CID, null, $run->id);
        try { $this->pr()->updateLine(self::CID, $run->id, $run->lines->first()->id, ['commission' => 1]); $this->fail('posted'); } catch (ValidationException) {}
        $this->pr()->pay(self::CID, null, $run->id, $this->sys('bank'), '2026-02-01');
        try { $this->pr()->unpost(self::CID, $run->id); $this->fail('paid'); } catch (ValidationException) {}
        $this->pr()->unpay(self::CID, $run->id);
        $this->pr()->unpost(self::CID, $run->id);
        $this->assertBooksBalance();
    }

    public function test_negative_net_pay_is_refused(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 10));
        $this->emp();
        $run = $this->pr()->createRun(self::CID, null, '2026-01');
        $this->expectException(ValidationException::class);
        $this->pr()->updateLine(self::CID, $run->id, $run->lines->first()->id, ['other_deductions' => 99999]);
    }

    public function test_end_of_service_settlement_trues_up_provisions(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 5));
        $e = $this->emp(['joined_on' => '2024-06-30', 'left_on' => '2026-06-30']);
        $period = '2026-06';
        // the system needs at least one posted month holding provisions; run Jun only (employee joined long before)
        $run = $this->pr()->createRun(self::CID, null, $period);
        $q = $this->pr()->quote(self::CID, $e->id);
        $this->assertFalse($q['can_settle'], 'the final month must be posted first');
        $this->pr()->post(self::CID, null, $run->id);

        $q = $this->pr()->quote(self::CID, $e->id);
        $this->assertTrue($q['can_settle']);
        $this->assertEqualsWithDelta(2.0, $q['years_of_service'], 0.01);
        $this->assertEqualsWithDelta(3000 / 30 * 21 * 2.0, $q['due']['eos'], 5.0);
        $this->assertSame(175.0, $q['provision_held']['eos']);

        try { $this->pr()->settle(self::CID, null, $e->id, ['date' => '2026-06-30']); $this->fail('needs a payment account'); } catch (ValidationException) {}
        $this->pr()->settle(self::CID, null, $e->id, ['date' => '2026-06-30', 'account_ledger_id' => $this->sys('bank')]);
        foreach (['eos_provision', 'leave_provision', 'ticket_provision'] as $k) { $this->assertSame(0.0, $this->bal($k), "$k must be cleared"); }
        $this->assertEqualsWithDelta(-$q['total_due'], $this->bal('bank'), 0.01);
        $this->assertSame('left', $e->fresh()->status);
        $this->assertBooksBalance();
        try { $this->pr()->settle(self::CID, null, $e->id, ['date' => '2026-06-30', 'account_ledger_id' => $this->sys('bank')]); $this->fail('twice'); } catch (ValidationException) {}
    }

    public function test_settled_employee_drops_out_of_later_payroll(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 5));
        $e = $this->emp(['joined_on' => '2025-01-01', 'left_on' => '2026-06-30']);
        $this->emp(['name' => 'Ben', 'basic' => 1000, 'allowance' => 0]);
        $jun = $this->pr()->createRun(self::CID, null, '2026-06');
        $this->pr()->post(self::CID, null, $jun->id);
        $this->pr()->settle(self::CID, null, $e->id, ['date' => '2026-06-30', 'account_ledger_id' => $this->sys('bank')]);
        $jul = $this->pr()->createRun(self::CID, null, '2026-07');
        $this->assertCount(1, $jul->lines);
        $this->assertSame(1000.0, $jul->total_net);
    }

    // ─── petty cash ───
    public function test_petty_cash_float_topup_spend_and_replenish(): void
    {
        $this->svc->settings(self::CID)->update(['petty_cash_limit' => 500]);
        $this->petty()->topUp(self::CID, null, ['date' => '2026-06-01', 'from_ledger_id' => $this->sys('bank'), 'amount' => 500]);
        $this->assertSame(500.0, $this->bal('petty_cash'));
        try { $this->petty()->topUp(self::CID, null, ['date' => '2026-06-02', 'from_ledger_id' => $this->sys('bank'), 'amount' => 1]); $this->fail('over float'); } catch (ValidationException) {}

        $rent = \App\Modules\Accounts\Models\AccLedger::where('name', 'Travel & Conveyance')->value('id');
        $this->petty()->spend(self::CID, null, ['date' => '2026-06-03', 'ledger_id' => $rent, 'amount' => 120, 'description' => 'Taxi']);
        $this->petty()->spend(self::CID, null, ['date' => '2026-06-04', 'ledger_id' => $rent, 'amount' => 80]);
        $s = $this->petty()->summary(self::CID, '2026-06-01', '2026-06-30');
        $this->assertSame(300.0, $s['balance']);
        $this->assertSame(200.0, $s['spent']);
        $this->assertSame(500.0, $s['topups']);
        $this->assertSame(200.0, $s['replenish_needed']);
        $this->assertSame('Travel & Conveyance', $s['spent_by_category'][0]->name);

        $this->petty()->topUp(self::CID, null, ['date' => '2026-06-30', 'from_ledger_id' => $this->sys('bank')]);   // no amount → back to the float
        $this->assertSame(500.0, $this->bal('petty_cash'));
        $this->assertBooksBalance();
    }

    public function test_petty_cash_cannot_go_negative(): void
    {
        $this->petty()->topUp(self::CID, null, ['date' => '2026-06-01', 'from_ledger_id' => $this->sys('bank'), 'amount' => 100]);
        $exp = \App\Modules\Accounts\Models\AccLedger::where('name', 'Bank Charges')->value('id');
        $this->expectException(ValidationException::class);
        $this->petty()->spend(self::CID, null, ['date' => '2026-06-02', 'ledger_id' => $exp, 'amount' => 150]);
    }

    public function test_petty_cash_balance_is_checked_on_the_voucher_date(): void
    {
        $this->petty()->topUp(self::CID, null, ['date' => '2026-06-10', 'from_ledger_id' => $this->sys('bank'), 'amount' => 100]);
        $exp = \App\Modules\Accounts\Models\AccLedger::where('name', 'Bank Charges')->value('id');
        $this->expectException(ValidationException::class);          // nothing was in the box on 5 June
        $this->petty()->spend(self::CID, null, ['date' => '2026-06-05', 'ledger_id' => $exp, 'amount' => 50]);
    }
}
