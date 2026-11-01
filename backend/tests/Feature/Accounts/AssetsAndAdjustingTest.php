<?php

namespace Tests\Feature\Accounts;

use App\Modules\Accounts\Models\AccAsset;
use App\Modules\Accounts\Support\AssetService;
use App\Modules\Accounts\Support\JournalService;
use Illuminate\Validation\ValidationException;

class AssetsAndAdjustingTest extends AccountsTestCase
{
    private function assets(): AssetService { return app(AssetService::class); }
    private function journals(): JournalService { return app(JournalService::class); }

    private function laptop(array $x = []): AccAsset
    {
        return $this->assets()->create(self::CID, null, $x + ['name' => 'Laptop', 'category' => 'equipment', 'purchase_date' => '2026-01-10', 'cost' => 12000,
            'salvage_value' => 0, 'useful_life_months' => 12, 'method' => 'sl', 'paid_from_ledger_id' => $this->sys('bank')]);
    }

    public function test_purchase_posts_cost_to_the_asset_ledger(): void
    {
        $a = $this->laptop();
        $this->assertSame(12000.0, $this->bal('equipment'));
        $this->assertSame(-12000.0, $this->bal('bank'));
        $this->assertNotNull($a->acquisition_voucher_id);
        $this->assertBooksBalance();
    }

    public function test_straight_line_depreciation_runs_monthly_and_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 4, 5));
        $a = $this->laptop();                                         // 1000 / month starting January (month of purchase)
        $r = $this->assets()->run(self::CID, null);                   // Jan, Feb, Mar completed
        $this->assertSame(3, $r['posted']);
        $this->assertSame(3000.0, $r['total']);
        $this->assertSame(3000.0, $a->fresh()->accumulated);
        $this->assertSame(-3000.0, $this->bal('accum_depreciation'));
        $this->assertSame(3000.0, $this->bal('depreciation'));
        $this->assertSame(0, $this->assets()->run(self::CID, null)['posted'], 'running again must not double-post');
        $this->assertBooksBalance();
    }

    public function test_never_depreciates_a_month_that_has_not_finished(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 20));
        $this->laptop();
        $this->assertSame(0, $this->assets()->run(self::CID, null, '2026-12-31')['posted']);
    }

    public function test_depreciation_stops_at_salvage_value_with_no_rounding_stub(): void
    {
        $this->travelTo(now()->setDate(2028, 6, 1));
        $a = $this->laptop(['cost' => 10000, 'salvage_value' => 1000, 'useful_life_months' => 7]);   // 9000/7 = 1285.71…
        $this->assets()->run(self::CID, null);
        $this->assertSame(9000.0, $a->fresh()->accumulated);
        $this->assertSame(1000.0, $a->fresh()->cost - $a->fresh()->accumulated);
        $this->assertTrue($this->assets()->register(self::CID)['rows'][0]['fully_depreciated']);
    }

    public function test_written_down_value_method(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 5));
        $a = $this->laptop(['method' => 'wdv', 'wdv_rate' => 24, 'cost' => 10000, 'useful_life_months' => 60]);  // 2% of book value per month
        $this->assets()->run(self::CID, null);                         // Jan 200.00, Feb 196.00
        $this->assertSame(396.0, $a->fresh()->accumulated);
    }

    public function test_undo_only_the_latest_month(): void
    {
        $this->travelTo(now()->setDate(2026, 4, 5));
        $a = $this->laptop();
        $this->assets()->run(self::CID, null);
        try { $this->assets()->undoRun(self::CID, '2026-01-31'); $this->fail('not the latest'); } catch (ValidationException) {}
        $this->assets()->undoRun(self::CID, '2026-03-31');
        $this->assertSame(2000.0, $a->fresh()->accumulated);
        $this->assertSame('2026-02-28', $a->fresh()->last_depreciated_to->toDateString());
        $this->assertSame(1, $this->assets()->run(self::CID, null)['posted']);   // March can be re-run
        $this->assertBooksBalance();
    }

    public function test_existing_asset_continues_from_where_it_left_off(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 5));
        $a = $this->assets()->create(self::CID, null, ['name' => 'Old truck', 'category' => 'vehicle', 'purchase_date' => '2024-01-01', 'cost' => 60000, 'salvage_value' => 0,
            'useful_life_months' => 60, 'acquisition' => 'existing', 'opening_accumulated' => 24000, 'depreciated_upto' => '2026-03-15']);
        $this->assertSame(0, \DB::table('acc_entries')->count(), 'an existing asset is not re-posted');
        $r = $this->assets()->run(self::CID, null);                    // April, May → 2 × 1000
        $this->assertSame(2, $r['posted']);
        $this->assertSame(26000.0, $a->fresh()->accumulated);
        $this->assets()->undoRun(self::CID, '2026-05-31'); $this->assets()->undoRun(self::CID, '2026-04-30');
        $this->assertSame('2026-03-31', $a->fresh()->last_depreciated_to->toDateString());   // falls back to the opening point, not null
        $this->assertSame(24000.0, $a->fresh()->accumulated);
    }

    public function test_disposal_books_gain_or_loss(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 5));
        $a = $this->laptop();                                          // after Jan–May: 5000 depreciated, book value 7000
        $this->assets()->run(self::CID, null);
        try { $this->assets()->dispose(self::CID, null, $a->id, ['date' => '2026-08-01', 'proceeds' => 0]); $this->fail('depreciation incomplete'); } catch (ValidationException) {}
        $this->assets()->dispose(self::CID, null, $a->id, ['date' => '2026-06-20', 'proceeds' => 8000, 'account_ledger_id' => $this->sys('bank')]);
        $this->assertSame(0.0, $this->bal('equipment'));
        $this->assertSame(0.0, $this->bal('accum_depreciation'));
        $this->assertSame(-1000.0, $this->bal('disposal_gain_loss'));  // credit = gain of 1000 over book value 7000
        $this->assertSame('disposed', $a->fresh()->status);
        $this->assertBooksBalance();

        $b = $this->laptop(['name' => 'Scrapped', 'purchase_date' => '2026-06-01']);
        $this->assets()->dispose(self::CID, null, $b->id, ['date' => '2026-06-25']);   // written off for nothing
        $this->assertSame(12000.0 - 1000.0, $this->bal('disposal_gain_loss'));
        $this->assertBooksBalance();
        $this->expectException(ValidationException::class);
        $this->assets()->dispose(self::CID, null, $b->id, ['date' => '2026-06-26']);
    }

    public function test_money_fields_lock_after_depreciation_and_delete_removes_purchase_entry(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 5));
        $a = $this->laptop();
        $this->assets()->update(self::CID, $a->id, ['name' => 'Laptop Pro']);       // harmless
        try { $this->assets()->update(self::CID, $a->id, ['cost' => 9999]); $this->fail('purchase entry is in the books'); } catch (ValidationException) {}
        $b = $this->laptop(['name' => 'Tablet', 'cost' => 500]);
        $this->assets()->delete(self::CID, $b->id);
        $this->assertSame(12000.0, $this->bal('equipment'));
        $this->assets()->run(self::CID, null);
        $this->expectException(ValidationException::class);
        $this->assets()->delete(self::CID, $a->id);
    }

    public function test_year_cannot_close_with_depreciation_outstanding(): void
    {
        $this->travelTo(now()->setDate(2027, 5, 1));
        $this->laptop(['purchase_date' => '2026-05-10']);
        $fy = app(\App\Modules\Accounts\Support\FiscalYearService::class);
        $fy->ensure(self::CID);
        $id = \App\Modules\Accounts\Models\AccFiscalYear::where('start_date', '2026-04-01')->value('id');
        $this->assertFalse($fy->preview(self::CID, $id)['can_close']);
        $this->assets()->run(self::CID, null);
        $this->assertTrue($fy->preview(self::CID, $id)['can_close']);
        $fy->close(self::CID, null, $id);
        $this->assertBooksBalance();
    }

    // ─── adjusting entries ───
    public function test_accrual_posts_and_reverses_itself(): void
    {
        $rent = \App\Modules\Accounts\Models\AccLedger::where('name', 'Office Rent')->value('id');
        $v = $this->journals()->adjusting(self::CID, null, ['kind' => 'accrued_expense', 'date' => '2026-06-30', 'amount' => 800, 'ledger_id' => $rent, 'auto_reverse' => true]);
        $this->assertSame('2026-07-01', $v->reverse_on->toDateString());
        $this->assertSame(-800.0, $this->bal('accrued_expenses'));
        $this->assertSame(0, $this->journals()->postDueReversals(self::CID, '2026-06-30'));
        $this->assertSame(1, $this->journals()->postDueReversals(self::CID, '2026-07-01'));
        $this->assertSame(0, $this->journals()->postDueReversals(self::CID, '2026-07-31'), 'a reversal is posted exactly once');
        $this->assertSame(0.0, $this->bal('accrued_expenses'));
        $this->assertSame(0.0, $this->svc->ledgerBalance(self::CID, $rent));
        $this->assertBooksBalance();

        try { $this->svc->deleteVoucher(self::CID, $v); $this->fail('has a reversal'); } catch (ValidationException) {}
        $this->journals()->cancelReversal(self::CID, $v->id);
        $this->svc->deleteVoucher(self::CID, $v->fresh());
    }

    public function test_prepaid_then_amortize_cannot_exceed_balance(): void
    {
        $rent = \App\Modules\Accounts\Models\AccLedger::where('name', 'Office Rent')->value('id');
        $this->journals()->adjusting(self::CID, null, ['kind' => 'prepaid_payment', 'date' => '2026-04-01', 'amount' => 1200, 'account_ledger_id' => $this->sys('bank')]);
        $this->journals()->adjusting(self::CID, null, ['kind' => 'prepaid_amortize', 'date' => '2026-04-30', 'amount' => 100, 'ledger_id' => $rent]);
        $this->assertSame(1100.0, $this->bal('prepaid'));
        $this->expectException(ValidationException::class);
        $this->journals()->adjusting(self::CID, null, ['kind' => 'prepaid_amortize', 'date' => '2026-05-31', 'amount' => 2000, 'ledger_id' => $rent]);
    }

    public function test_unearned_revenue_cycle(): void
    {
        $rev = $this->sys('service_revenue');
        $this->journals()->adjusting(self::CID, null, ['kind' => 'unearned_receive', 'date' => '2026-04-01', 'amount' => 3000, 'account_ledger_id' => $this->sys('bank')]);
        $this->assertSame(-3000.0, $this->bal('unearned_revenue'));
        $this->journals()->adjusting(self::CID, null, ['kind' => 'unearned_recognize', 'date' => '2026-04-30', 'amount' => 1000, 'ledger_id' => $rev]);
        $this->assertSame(-2000.0, $this->bal('unearned_revenue'));
        $this->assertSame(1000.0, $this->svc->profitLoss(self::CID, '2026-04-01', '2026-04-30')['revenue']);
        try { $this->journals()->adjusting(self::CID, null, ['kind' => 'unearned_recognize', 'date' => '2026-05-31', 'amount' => 5000, 'ledger_id' => $rev]); $this->fail('too much'); } catch (ValidationException) {}
        $this->assertBooksBalance();
    }

    public function test_auto_reverse_only_for_accruals(): void
    {
        $this->expectException(ValidationException::class);
        $this->journals()->adjusting(self::CID, null, ['kind' => 'prepaid_payment', 'date' => '2026-04-01', 'amount' => 10, 'account_ledger_id' => $this->sys('bank'), 'auto_reverse' => true]);
    }
}
