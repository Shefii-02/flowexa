<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccAllocation;
use App\Modules\Accounts\Support\PaymentService;
use Illuminate\Validation\ValidationException;

class PaymentsTest extends AccountsTestCase
{
    private function pay(): PaymentService { return app(PaymentService::class); }

    private function invoice($c, float $amt, string $date = '2026-06-01', array $x = [])
    {
        return $this->pay()->save(self::CID, null, $x + ['type' => 'sales', 'date' => $date, 'party_ledger_id' => $c->id, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => $amt, 'gst_rate' => 0]]]);
    }

    private function receipt($c, float $amt, array $x = [])
    {
        return $this->pay()->save(self::CID, null, $x + ['type' => 'receipt', 'date' => '2026-06-10', 'party_ledger_id' => $c->id, 'account_ledger_id' => $this->sys('bank'), 'amount' => $amt]);
    }

    public function test_partial_then_full_settlement_updates_status(): void
    {
        $c = $this->party('Cust');
        $i = $this->invoice($c, 1000);
        $this->receipt($c, 400, ['allocations' => [['voucher_id' => $i->id, 'amount' => 400]]]);
        $row = $this->pay()->documents(self::CID, 'receivable', ['as_of' => '2026-06-15'])['rows'][0];
        $this->assertSame('overdue', $row['status']);          // no credit terms → due on the invoice date
        $this->assertSame(600.0, $row['outstanding']);
        $this->assertSame(400.0, $row['settled']);

        $this->receipt($c, 600, ['allocations' => [['voucher_id' => $i->id, 'amount' => 600]], 'date' => '2026-06-20']);
        $this->assertSame('paid', $this->pay()->documents(self::CID, 'receivable', ['as_of' => '2026-06-30'])['rows'][0]['status']);
        $this->assertSame(0.0, $this->bal('bank') - 1000.0);
        $this->assertBooksBalance();
    }

    public function test_cannot_overpay_an_invoice_or_allocate_more_than_the_receipt(): void
    {
        $c = $this->party('Cust');
        $i = $this->invoice($c, 500);
        try { $this->receipt($c, 900, ['allocations' => [['voucher_id' => $i->id, 'amount' => 600]]]); $this->fail('over invoice'); }
        catch (ValidationException $e) { $this->assertStringContainsString('outstanding', $e->errors()['allocations'][0]); }
        try { $this->receipt($c, 100, ['allocations' => [['voucher_id' => $i->id, 'amount' => 300]]]); $this->fail('over receipt'); }
        catch (ValidationException $e) { $this->assertStringContainsString('more than', $e->errors()['allocations'][0]); }
        $this->assertSame(0, \DB::table('acc_vouchers')->where('type', 'receipt')->count(), 'a failed save must roll back the voucher');
    }

    public function test_allocation_must_match_party_and_date(): void
    {
        $a = $this->party('A'); $b = $this->party('B');
        $ia = $this->invoice($a, 500);
        try { $this->receipt($b, 500, ['allocations' => [['voucher_id' => $ia->id, 'amount' => 500]]]); $this->fail('other party'); } catch (ValidationException) {}
        try { $this->receipt($a, 500, ['date' => '2026-05-01', 'allocations' => [['voucher_id' => $ia->id, 'amount' => 500]]]); $this->fail('before invoice'); } catch (ValidationException) {}
        $this->assertTrue(true);
    }

    public function test_auto_allocate_pays_oldest_due_first(): void
    {
        $c = $this->party('Cust');
        $late = $this->invoice($c, 300, '2026-05-01');
        $new  = $this->invoice($c, 300, '2026-06-01');
        $r = $this->receipt($c, 400, ['auto_allocate' => true]);
        $this->assertEquals(300, AccAllocation::where('payment_voucher_id', $r->id)->where('invoice_voucher_id', $late->id)->value('amount'));
        $this->assertEquals(100, AccAllocation::where('payment_voucher_id', $r->id)->where('invoice_voucher_id', $new->id)->value('amount'));
    }

    public function test_credit_note_can_settle_an_invoice(): void
    {
        $c = $this->party('Cust');
        $i = $this->invoice($c, 1000);
        $this->pay()->save(self::CID, null, ['type' => 'credit_note', 'date' => '2026-06-05', 'party_ledger_id' => $c->id,
            'items' => [['description' => 'return', 'qty' => 1, 'rate' => 200, 'gst_rate' => 0]], 'allocations' => [['voucher_id' => $i->id, 'amount' => 200]]]);
        $this->assertSame(800.0, $this->pay()->documents(self::CID, 'receivable', [])['rows'][0]['outstanding']);
    }

    public function test_deleting_receipt_frees_the_invoice_but_invoice_with_payments_is_protected(): void
    {
        $c = $this->party('Cust');
        $i = $this->invoice($c, 1000);
        $r = $this->receipt($c, 1000, ['allocations' => [['voucher_id' => $i->id, 'amount' => 1000]]]);
        try { $this->pay()->delete(self::CID, $i); $this->fail('has payments'); } catch (ValidationException) {}
        $this->pay()->delete(self::CID, $r);
        $this->assertSame(1000.0, $this->pay()->documents(self::CID, 'receivable', [])['rows'][0]['outstanding']);
        $this->pay()->delete(self::CID, $i);
        $this->assertSame(0, \DB::table('acc_vouchers')->count());
    }

    public function test_cannot_edit_invoice_below_settled_amount(): void
    {
        $c = $this->party('Cust');
        $i = $this->invoice($c, 1000);
        $this->receipt($c, 700, ['allocations' => [['voucher_id' => $i->id, 'amount' => 700]]]);
        $this->expectException(ValidationException::class);
        $this->pay()->save(self::CID, null, ['type' => 'sales', 'date' => '2026-06-01', 'party_ledger_id' => $c->id, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => 500]]], $i);
    }

    public function test_supplier_bills_use_payable_family_and_net_of_tds(): void
    {
        $s = $this->party('Vendor', 'creditor');
        $b = $this->pay()->save(self::CID, null, ['type' => 'expense', 'date' => '2026-06-01', 'party_ledger_id' => $s->id, 'ledger_id' => $this->svc->sys(self::CID, 'salaries'),
            'amount' => 1000, 'tds_rate' => 10, 'tds_section' => '194J']);
        $row = $this->pay()->documents(self::CID, 'payable', [])['rows'][0];
        $this->assertSame(900.0, $row['total']);                    // 1000 less 100 TDS
        $this->pay()->save(self::CID, null, ['type' => 'payment', 'date' => '2026-06-10', 'party_ledger_id' => $s->id, 'account_ledger_id' => $this->sys('bank'), 'amount' => 900,
            'allocations' => [['voucher_id' => $b->id, 'amount' => 900]]]);
        $this->assertSame('paid', $this->pay()->documents(self::CID, 'payable', [])['rows'][0]['status']);
        $this->assertSame([], $this->pay()->documents(self::CID, 'receivable', [])['rows']);
    }

    public function test_cash_expense_is_not_a_payable_document(): void
    {
        $this->book(['type' => 'expense', 'date' => '2026-06-01', 'ledger_id' => $this->sys('salaries'), 'account_ledger_id' => $this->sys('cash'), 'amount' => 50]);
        $this->assertSame([], $this->pay()->documents(self::CID, 'payable', [])['rows']);
    }

    public function test_advance_receipt_sits_in_liability_then_is_applied(): void
    {
        $c = $this->party('Cust');
        $adv = $this->receipt($c, 500, ['is_advance' => true]);
        $this->assertSame(-500.0, $this->bal('deferred_revenue'));   // credit
        $this->assertSame(0.0, $this->svc->ledgerBalance(self::CID, $c->id));
        $this->assertSame(500.0, $this->pay()->advances(self::CID, 'receivable')['total']);

        $i = $this->invoice($c, 800, '2026-06-15');
        $a = $this->pay()->applyAdvance(self::CID, null, $adv->id, $i->id, 500, '2026-06-20');
        $this->assertSame(0.0, $this->bal('deferred_revenue'));
        $this->assertSame(300.0, $this->svc->ledgerBalance(self::CID, $c->id));
        $this->assertSame(300.0, $this->pay()->outstandingOf($i));
        $this->assertSame(0.0, $this->pay()->advances(self::CID, 'receivable')['total']);
        $this->assertBooksBalance();

        // the advance is now committed; deleting it, or the invoice, is refused until un-applied
        try { $this->pay()->delete(self::CID, $adv); $this->fail('applied advance'); } catch (ValidationException) {}
        $this->pay()->unapplyAdvance(self::CID, $a->id);
        $this->assertSame(-500.0, $this->bal('deferred_revenue'));
        $this->assertSame(800.0, $this->pay()->outstandingOf($i));
        $this->assertBooksBalance();
    }

    public function test_cannot_apply_more_than_the_advance_or_to_another_party(): void
    {
        $c = $this->party('Cust'); $d = $this->party('Other');
        $adv = $this->receipt($c, 100, ['is_advance' => true]);
        $i = $this->invoice($c, 800, '2026-06-15');
        $j = $this->invoice($d, 800, '2026-06-15');
        try { $this->pay()->applyAdvance(self::CID, null, $adv->id, $i->id, 150, '2026-06-20'); $this->fail('over'); } catch (ValidationException) {}
        try { $this->pay()->applyAdvance(self::CID, null, $adv->id, $j->id, 50, '2026-06-20'); $this->fail('party'); } catch (ValidationException) {}
        $this->assertTrue(true);
    }

    public function test_supplier_advance_symmetry(): void
    {
        $s = $this->party('Vendor', 'creditor');
        $adv = $this->pay()->save(self::CID, null, ['type' => 'payment', 'date' => '2026-06-01', 'party_ledger_id' => $s->id, 'account_ledger_id' => $this->sys('bank'), 'amount' => 200, 'is_advance' => true]);
        $this->assertSame(200.0, $this->bal('supplier_advances'));
        $bill = $this->pay()->save(self::CID, null, ['type' => 'purchase', 'date' => '2026-06-05', 'party_ledger_id' => $s->id, 'items' => [['description' => 'g', 'qty' => 1, 'rate' => 500, 'gst_rate' => 0]]]);
        $this->pay()->applyAdvance(self::CID, null, $adv->id, $bill->id, 200, '2026-06-06');
        $this->assertSame(0.0, $this->bal('supplier_advances'));
        $this->assertSame(300.0, $this->pay()->outstandingOf($bill));
        $this->assertBooksBalance();
    }

    public function test_ageing_buckets_and_on_account(): void
    {
        $c = $this->party('Cust', 'debtor', ['credit_days' => 30]);
        $this->invoice($c, 100, '2026-01-01');   // due 31 Jan → 60 days late at 1 Apr
        $this->invoice($c, 200, '2026-03-20');   // due 19 Apr → not yet due
        $this->receipt($c, 50, ['date' => '2026-03-25']);   // unallocated, on account
        $ag = $this->pay()->ageing(self::CID, 'receivable', '2026-04-01');
        $row = $ag['rows'][0];
        $this->assertSame(100.0, $row['d31_60']);
        $this->assertSame(200.0, $row['current']);
        $this->assertSame(300.0, $row['outstanding']);
        $this->assertSame(-50.0, $row['on_account']);       // ledger says 250 owed; invoices say 300
        $this->assertSame(250.0, $row['ledger_balance']);
    }

    public function test_payment_report_totals(): void
    {
        $c = $this->party('Cust');
        $i = $this->invoice($c, 1000);
        $this->receipt($c, 600, ['allocations' => [['voucher_id' => $i->id, 'amount' => 400]]]);
        $this->pay()->save(self::CID, null, ['type' => 'payment', 'date' => '2026-06-12', 'party_ledger_id' => $this->party('V', 'creditor')->id, 'account_ledger_id' => $this->sys('cash'), 'amount' => 100]);
        $r = $this->pay()->report(self::CID, '2026-06-01', '2026-06-30');
        $this->assertSame(600.0, $r['total_received']);
        $this->assertSame(100.0, $r['total_paid']);
        $this->assertSame(500.0, $r['net']);
        $this->assertSame(200.0, collect($r['rows'])->firstWhere('direction', 'in')['unallocated']);
    }
}
