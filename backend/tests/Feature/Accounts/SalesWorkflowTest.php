<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccQuotation;
use App\Modules\Accounts\Models\AccVoucher;
use App\Modules\Accounts\Support\QuotationService;
use Illuminate\Validation\ValidationException;

class SalesWorkflowTest extends AccountsTestCase
{
    private function q(): QuotationService { return app(QuotationService::class); }

    private function quote(array $over = [])
    {
        $c = $over['party'] ?? $this->party('Acme');
        unset($over['party']);
        return $this->q()->save(self::CID, null, $over + ['date' => now()->toDateString(), 'party_ledger_id' => $c->id,
            'items' => [['description' => 'Setup', 'qty' => 2, 'rate' => 500, 'gst_rate' => 18]]]);
    }

    public function test_quotation_prices_gst_and_posts_nothing(): void
    {
        $q = $this->quote();
        $this->assertSame('QT-0001', $q->number);
        $this->assertSame(1000.0, $q->taxable);
        $this->assertSame(90.0, $q->cgst);
        $this->assertSame(1180.0, $q->total);
        $this->assertSame(0, \DB::table('acc_entries')->count(), 'a quotation must not touch the books');
    }

    public function test_interstate_quotation_uses_igst(): void
    {
        $q = $this->quote(['place_of_supply' => '27']);
        $this->assertSame(180.0, $q->igst);
        $this->assertSame(0.0, $q->cgst);
    }

    public function test_convert_creates_matching_invoice_once(): void
    {
        $q = $this->quote();
        $this->q()->setStatus(self::CID, $q->id, 'accepted');
        [$q2, $v] = $this->q()->convert(self::CID, null, $q->id);
        $this->assertSame('invoiced', $q2->status);
        $this->assertSame($q->total, $v->total);
        $this->assertSame($v->id, $q2->converted_voucher_id);
        $this->assertSame(1180.0, $this->bal('sales') * -1 + $this->bal('out_cgst') * -1 + $this->bal('out_sgst') * -1);
        $this->assertBooksBalance();

        $this->expectException(ValidationException::class);
        $this->q()->convert(self::CID, null, $q->id);
    }

    public function test_deleting_the_invoice_reopens_the_quotation(): void
    {
        $q = $this->quote();
        [, $v] = $this->q()->convert(self::CID, null, $q->id);
        $this->svc->deleteVoucher(self::CID, $v);
        $fresh = AccQuotation::find($q->id);
        $this->assertSame('accepted', $fresh->status);
        $this->assertNull($fresh->converted_voucher_id);
        [, $v2] = $this->q()->convert(self::CID, null, $q->id);   // and it can be invoiced again
        $this->assertNotNull($v2->id);
    }

    public function test_status_rules_and_expiry(): void
    {
        $q = $this->quote();
        $this->q()->setStatus(self::CID, $q->id, 'rejected');
        try { $this->q()->convert(self::CID, null, $q->id); $this->fail('rejected cannot be invoiced'); } catch (ValidationException) {}
        try { $this->q()->setStatus(self::CID, $q->id, 'accepted'); $this->fail('rejected -> accepted is not allowed'); } catch (ValidationException) {}

        $old = $this->quote(['date' => now()->subDays(40)->toDateString(), 'valid_until' => now()->subDays(10)->toDateString()]);
        $this->assertSame('expired', $old->fresh()->effective_status);
        try { $this->q()->convert(self::CID, null, $old->id); $this->fail('expired'); } catch (ValidationException) {}
        // extending validity revives it
        $this->q()->save(self::CID, null, ['date' => now()->toDateString(), 'valid_until' => now()->addDays(5)->toDateString(), 'party_ledger_id' => $old->party_ledger_id,
            'items' => [['description' => 'Setup', 'qty' => 1, 'rate' => 100]]], $old);
        $this->assertSame('draft', $old->fresh()->effective_status);
    }

    public function test_accepted_quotation_cannot_be_edited_but_can_be_duplicated(): void
    {
        $q = $this->quote();
        $this->q()->setStatus(self::CID, $q->id, 'accepted');
        try { $this->q()->save(self::CID, null, ['date' => now()->toDateString(), 'party_ledger_id' => $q->party_ledger_id, 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 1]]], $q->fresh()); $this->fail('locked'); }
        catch (ValidationException) {}
        $copy = $this->q()->duplicate(self::CID, null, $q->id);
        $this->assertSame('draft', $copy->status);
        $this->assertSame($q->total, $copy->total);
        $this->assertNotSame($q->number, $copy->number);
    }

    public function test_credit_limit_blocks_then_override_allows(): void
    {
        $c = $this->party('Limited', 'debtor', ['credit_limit' => 1500, 'credit_days' => 30]);
        $inv = fn (array $x = []) => $this->book($x + ['type' => 'sales', 'date' => '2026-06-01', 'party_ledger_id' => $c->id, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]]]);
        $first = $inv();                                              // 1000 of 1500
        try { $inv(); $this->fail('second invoice breaches the limit'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('credit_limit', $e->errors()); }
        $second = $inv(['credit_override' => true]);
        $this->assertStringContainsString('Credit limit overridden', $second->narration);
        // editing the first invoice without raising it is not a new credit request
        $this->book(['type' => 'sales', 'date' => '2026-06-01', 'party_ledger_id' => $c->id, 'narration' => 'edited',
            'items' => [['description' => 'a', 'qty' => 1, 'rate' => 900, 'gst_rate' => 0]]] , $first) ;
    }

    public function test_credit_hold_blocks_new_invoices(): void
    {
        $c = $this->party('Held', 'debtor', ['credit_hold' => true]);
        $this->expectException(ValidationException::class);
        $this->book(['type' => 'sales', 'date' => '2026-06-01', 'party_ledger_id' => $c->id, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => 10]]]);
    }

    public function test_due_date_follows_customer_terms(): void
    {
        $c = $this->party('Net30', 'debtor', ['credit_days' => 30]);
        $v = $this->book(['type' => 'sales', 'date' => '2026-06-01', 'party_ledger_id' => $c->id, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => 10]]]);
        $this->assertSame('2026-07-01', $v->due_date->toDateString());
        $v2 = $this->book(['type' => 'sales', 'date' => '2026-06-01', 'due_date' => '2026-06-10', 'party_ledger_id' => $c->id, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => 10]]]);
        $this->assertSame('2026-06-10', $v2->due_date->toDateString());
    }

    public function test_credit_overview_statuses(): void
    {
        $a = $this->party('Over', 'debtor', ['credit_limit' => 100]);
        $this->party('Fine', 'debtor', ['credit_limit' => 1000]);
        $this->book(['type' => 'sales', 'date' => '2026-06-01', 'party_ledger_id' => $a->id, 'credit_override' => true, 'items' => [['description' => 'a', 'qty' => 1, 'rate' => 500]]]);
        $o = $this->svc->creditOverview(self::CID);
        $this->assertSame(1, $o['over']);
        $this->assertSame('over', $o['rows'][0]['status']);
        $this->assertSame('Over', $o['rows'][0]['name']);
    }
}
