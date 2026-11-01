<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccEmployee;
use App\Modules\Accounts\Models\AccFiscalYear;
use App\Modules\Accounts\Models\AccItem;
use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccQuotation;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Realistic sample books for one company, built entirely through the real services (so every entry is balanced and obeys
 * the same rules as hand-typed data). Covers every part of the finance module; dates are relative to today.
 */
class DemoData
{
    private int $cid;
    private ?int $uid;
    private Carbon $today;
    private Carbon $S;   // current financial-year start
    private Carbon $P;   // previous financial-year start
    private array $n = [];

    public function __construct(
        private readonly AccountsService $svc,
        private readonly PaymentService $pay,
        private readonly QuotationService $quotes,
        private readonly AssetService $assets,
        private readonly JournalService $journals,
        private readonly PayrollService $payroll,
        private readonly PettyCashService $petty,
        private readonly FiscalYearService $fy,
    ) {}

    // ─── helpers ──────────────────────────────────────────────────────────────
    private function count(string $k): void { $this->n[$k] = ($this->n[$k] ?? 0) + 1; }
    private function L(string $name): int { return (int) AccLedger::where('company_id', $this->cid)->where('name', $name)->value('id'); }
    private function sys(string $k): int { return $this->svc->sys($this->cid, $k); }
    /** Date `$m` months after a base, on day `$d`, never later than today. */
    private function at(Carbon $base, int $m, int $d = 5): string
    {
        $c = $base->copy()->addMonthsNoOverflow($m); $c->day(min($d, $c->daysInMonth));
        return ($c->gt($this->today) ? $this->today : $c)->toDateString();
    }
    private function ago(int $days): string { return $this->today->copy()->subDays($days)->toDateString(); }
    private function line(string $desc, float $qty, float $rate, float $gst = 18, string $hsn = '9983', ?int $item = null): array
    {
        return ['item_id' => $item, 'description' => $desc, 'hsn' => $hsn, 'qty' => $qty, 'rate' => $rate, 'gst_rate' => $gst];
    }
    private function v(array $d, string $kind): AccVoucher
    {
        $this->count($kind);
        return $this->pay->save($this->cid, $this->uid, $d);
    }
    private function sale(AccLedger $c, string $date, array $lines, array $x = []): AccVoucher
    {
        return $this->v(['type' => 'sales', 'date' => $date, 'party_ledger_id' => $c->id, 'items' => $lines] + $x, 'invoices');
    }
    private function bill(AccLedger $s, string $date, array $lines, array $x = []): AccVoucher
    {
        return $this->v(['type' => 'purchase', 'date' => $date, 'party_ledger_id' => $s->id, 'items' => $lines] + $x, 'bills');
    }
    private function receive(AccLedger $c, string $date, float $amt, array $alloc = [], array $x = []): AccVoucher
    {
        return $this->v(['type' => 'receipt', 'date' => $date, 'party_ledger_id' => $c->id, 'account_ledger_id' => $this->sys('bank'), 'amount' => $amt,
            'allocations' => array_map(fn ($id, $a) => ['voucher_id' => $id, 'amount' => $a], array_keys($alloc), $alloc)] + $x, 'receipts');
    }
    private function payTo(AccLedger $s, string $date, float $amt, array $alloc = [], array $x = []): AccVoucher
    {
        return $this->v(['type' => 'payment', 'date' => $date, 'party_ledger_id' => $s->id, 'account_ledger_id' => $this->sys('bank'), 'amount' => $amt,
            'allocations' => array_map(fn ($id, $a) => ['voucher_id' => $id, 'amount' => $a], array_keys($alloc), $alloc)] + $x, 'payments');
    }
    private function spend(string $head, string $date, float $amt, string $from = 'bank', string $desc = '', float $gst = 0): AccVoucher
    {
        return $this->v(['type' => 'expense', 'date' => $date, 'ledger_id' => $this->L($head), 'account_ledger_id' => $this->sys($from), 'amount' => $amt,
            'gst_rate' => $gst, 'description' => $desc ?: $head], 'expenses');
    }
    private function journal(string $date, array $entries, string $sub = 'general', string $note = ''): AccVoucher
    {
        return $this->v(['type' => 'journal', 'date' => $date, 'sub_type' => $sub, 'narration' => $note, 'entries' => $entries], 'journals');
    }

    // ─── the story ────────────────────────────────────────────────────────────
    public function seed(int $cid, ?int $userId = null): array
    {
        $this->cid = $cid; $this->uid = $userId; $this->n = [];
        $this->today = now()->startOfDay();
        $set0 = $this->svc->settings($cid);
        if ($set0->demo_seeded_at) { throw new \RuntimeException('Demo data is already loaded. Remove it first (accounts:seed-demo ' . $cid . ' --remove).'); }
        if (AccVoucher::where('company_id', $cid)->exists()) { throw new \RuntimeException('This company already has accounting entries — demo data is only loaded into empty books so it can be removed cleanly.'); }
        $start = $this->maxIds();
        $before = collect($set0->getAttributes())->only(self::SETTINGS)->all();
        $this->svc->seedDefaults($cid);
        $this->svc->settings($cid)->update(['legal_name' => 'Demo Traders LLP', 'gstin' => '32AABCD1234E1Z5', 'pan' => 'AABCD1234E', 'state_code' => '32', 'city' => 'Kochi',
            'pincode' => '682001', 'address' => '12 Marine Drive, Kochi, Kerala', 'gst_registered' => true, 'fy_start_month' => 4, 'invoice_prefix' => 'INV',
            'petty_cash_limit' => 5000, 'quotation_validity_days' => 15]);
        $this->S = Carbon::parse($this->svc->fyRange($cid, $this->today->toDateString())[0]);
        $this->P = $this->S->copy()->subYear();

        $this->masterData();
        $this->openingBooks();
        $this->fixedAssets();
        $this->sales();
        $this->purchasesAndExpenses();
        $this->adjustingEntries();
        $this->pettyCash();
        $this->payrollCycle();
        $this->assetsDepreciationAndDisposal();
        $this->creditTermsAndReconcile();
        $this->closePreviousYear();

        DB::table('acc_settings')->where('company_id', $cid)->update(['demo_seeded_at' => now(), 'demo_marker' => json_encode(['start' => $start, 'end' => $this->maxIds(), 'settings' => $before])]);
        return $this->n;
    }

    private const TABLES = ['acc_ledgers', 'acc_items', 'acc_vouchers', 'acc_quotations', 'acc_assets', 'acc_employees', 'acc_payroll_runs', 'acc_fiscal_years'];
    private const SETTINGS = ['legal_name', 'gstin', 'pan', 'state_code', 'city', 'pincode', 'address', 'gst_registered', 'fy_start_month', 'invoice_prefix',
        'petty_cash_limit', 'quotation_validity_days', 'chart_version', 'locked_until'];

    private function maxIds(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => (int) DB::table($t)->max('id')])->all();
    }

    public function hasDemo(int $cid): bool
    {
        return (bool) DB::table('acc_settings')->where('company_id', $cid)->value('demo_seeded_at');
    }

    /**
     * Delete exactly what seed() created (rows added between the recorded start and end ids) and put the company's
     * settings back as they were. Refuses if anything real has been entered since, rather than deleting it.
     */
    public function remove(int $cid): array
    {
        $row = DB::table('acc_settings')->where('company_id', $cid)->first();
        if (!$row || !$row->demo_seeded_at || !$row->demo_marker) { throw new \RuntimeException('No demo data is loaded for this company.'); }
        $m = json_decode($row->demo_marker, true);
        $now = $this->maxIds();
        foreach (self::TABLES as $t) {
            if (DB::table($t)->where('company_id', $cid)->where('id', '>', $m['end'][$t])->exists()) {
                throw new \RuntimeException("Real entries were added after the demo data ({$t}). Delete the demo records by hand instead — nothing was removed.");
            }
        }
        $demo = fn (string $t) => DB::table($t)->where('company_id', $cid)->where('id', '>', $m['start'][$t])->where('id', '<=', $m['end'][$t]);
        $removed = [];
        DB::transaction(function () use ($cid, $m, $demo, &$removed) {
            $vouchers = $demo('acc_vouchers')->pluck('id');
            $quotes = $demo('acc_quotations')->pluck('id');
            $assets = $demo('acc_assets')->pluck('id');
            $runs = $demo('acc_payroll_runs')->pluck('id');
            DB::table('acc_entries')->whereIn('voucher_id', $vouchers)->delete();
            DB::table('acc_voucher_items')->whereIn('voucher_id', $vouchers)->delete();
            DB::table('acc_allocations')->where('company_id', $cid)->where(fn ($q) => $q->whereIn('payment_voucher_id', $vouchers)->orWhereIn('invoice_voucher_id', $vouchers))->delete();
            DB::table('acc_quotation_items')->whereIn('quotation_id', $quotes)->delete();
            DB::table('acc_asset_depreciations')->whereIn('asset_id', $assets)->delete();
            DB::table('acc_payroll_lines')->whereIn('run_id', $runs)->delete();
            foreach (['acc_vouchers', 'acc_quotations', 'acc_assets', 'acc_payroll_runs', 'acc_employees', 'acc_fiscal_years', 'acc_items', 'acc_ledgers'] as $t) {
                $removed[$t] = $demo($t)->delete();
            }
            DB::table('acc_settings')->where('company_id', $cid)->update($m['settings'] + ['demo_seeded_at' => null, 'demo_marker' => null]);
        });
        return $removed;
    }

    private array $c = []; private array $s = []; private array $it = [];

    private function party(string $name, string $group, array $x = []): AccLedger
    {
        return AccLedger::create(['company_id' => $this->cid, 'name' => $name, 'nature' => $group === 'debtor' ? 'asset' : 'liability', 'group' => $group, 'state_code' => '32',
            'code' => ChartOfAccounts::nextCode($this->cid, $group === 'debtor' ? 'asset' : 'liability', $group)] + $x);
    }

    private function masterData(): void
    {
        $this->c = [
            'acme' => $this->party('Acme Retail Pvt Ltd', 'debtor', ['gstin' => '32AAACA1111A1Z1', 'phone' => '9847000001', 'email' => 'accounts@acme.example', 'address' => '4 MG Road', 'city' => 'Kochi', 'pincode' => '682016']),
            'blue' => $this->party('Bluewave Technologies', 'debtor', ['gstin' => '32AAACB2222B1Z2', 'phone' => '9847000002', 'city' => 'Thrissur', 'pincode' => '680001', 'address' => '22 Round South']),
            'coastal' => $this->party('Coastal Foods', 'debtor', ['phone' => '9847000003', 'city' => 'Kozhikode']),
            'delta' => $this->party('Delta Infra (Mumbai)', 'debtor', ['gstin' => '27AAACD3333C1Z3', 'state_code' => '27', 'phone' => '9820000004', 'city' => 'Mumbai', 'pincode' => '400001', 'address' => '9 Nariman Point']),
            'ever' => $this->party('Evergreen Traders', 'debtor', ['phone' => '9847000005', 'city' => 'Kollam']),
        ];
        $this->s = [
            'tech' => $this->party('TechSupply Distributors', 'creditor', ['gstin' => '32AAACT4444D1Z4', 'phone' => '9847100001', 'city' => 'Kochi', 'pincode' => '682020', 'address' => '1 Industrial Estate']),
            'paper' => $this->party('PaperWorld Stationers', 'creditor', ['gstin' => '32AAACP5555E1Z5', 'phone' => '9847100002']),
            'law' => $this->party('Menon & Associates (Legal)', 'creditor', ['pan' => 'AAACM6666F', 'phone' => '9847100003']),
        ];
        foreach ([['Consulting Hour Pack', 'SRV-01', '9983', 'HRS', 18, 2500, 0, false], ['Router X200', 'HW-200', '8517', 'NOS', 18, 6500, 4800, true],
                  ['Cat6 Cable (305m)', 'HW-CAB', '8544', 'BOX', 18, 7200, 5600, true], ['A4 Paper Ream', 'ST-A4', '4802', 'REAM', 12, 320, 240, true]] as [$n, $sku, $hsn, $u, $g, $sale, $buy, $stock]) {
            $this->it[$sku] = AccItem::create(['company_id' => $this->cid, 'name' => $n, 'sku' => $sku, 'hsn' => $hsn, 'unit' => $u, 'gst_rate' => $g, 'sale_rate' => $sale, 'purchase_rate' => $buy,
                'track_stock' => $stock, 'opening_qty' => $stock ? 10 : 0, 'opening_value' => $stock ? 10 * $buy : 0, 'reorder_level' => $stock ? 5 : null])->id;
            $this->count('items');
        }
        foreach (['acme' => 30, 'blue' => 15, 'coastal' => 7, 'delta' => 45, 'ever' => 30] as $k => $days) { $this->c[$k]->update(['credit_days' => $days]); }
        foreach (['tech' => 30, 'paper' => 15, 'law' => 10] as $k => $days) { $this->s[$k]->update(['credit_days' => $days]); }
        $this->n['customers'] = count($this->c); $this->n['suppliers'] = count($this->s);
    }

    private function openingBooks(): void
    {
        $d = $this->at($this->P, 0, 1);
        $this->journal($d, [['ledger_id' => $this->sys('bank'), 'debit' => 2000000], ['ledger_id' => $this->sys('cash'), 'debit' => 25000], ['ledger_id' => $this->sys('capital'), 'credit' => 2025000]], 'equity', 'Owner capital introduced');
        $this->journal($this->at($this->P, 1, 3), [['ledger_id' => $this->sys('bank'), 'debit' => 300000], ['ledger_id' => $this->sys('lt_loan'), 'credit' => 300000]], 'liability', 'Term loan drawn');
        $this->journal($this->at($this->S, 2, 8), [['ledger_id' => $this->sys('bank'), 'debit' => 50000], ['ledger_id' => $this->sys('st_loan'), 'credit' => 50000]], 'liability', 'Short-term working-capital loan');
        $this->journal($this->at($this->S, 3, 20), [['ledger_id' => $this->sys('drawings'), 'debit' => 15000], ['ledger_id' => $this->sys('bank'), 'credit' => 15000]], 'equity', 'Owner drawings');
    }

    private function fixedAssets(): void
    {
        $bank = $this->sys('bank');
        foreach ([
            ['Dell Latitude Laptops (x4)', 'equipment', $this->at($this->P, 2, 10), 220000, 20000, 36, 'sl', null, 'Office'],
            ['Delivery Van', 'vehicle', $this->at($this->P, 5, 1), 650000, 50000, 60, 'wdv', 15, 'Garage'],
            ['Accounting Software Licence', 'intangible', $this->at($this->P, 3, 1), 72000, 0, 24, 'sl', null, null],
            ['Old Laser Printer', 'equipment', $this->at($this->P, 1, 15), 24000, 0, 24, 'sl', null, 'Front desk'],
        ] as [$name, $cat, $date, $cost, $salv, $life, $method, $rate, $loc]) {
            $this->assets->create($this->cid, $this->uid, ['name' => $name, 'category' => $cat, 'purchase_date' => $date, 'cost' => $cost, 'salvage_value' => $salv, 'useful_life_months' => $life,
                'method' => $method, 'wdv_rate' => $rate, 'paid_from_ledger_id' => $bank, 'location' => $loc]);
            $this->count('assets');
        }
    }

    private function sales(): void
    {
        [$acme, $blue, $coastal, $delta, $ever] = [$this->c['acme'], $this->c['blue'], $this->c['coastal'], $this->c['delta'], $this->c['ever']];
        $router = $this->it['HW-200']; $cable = $this->it['HW-CAB']; $consult = $this->it['SRV-01'];

        // previous year: steady trade, mostly collected
        foreach ([[2, $acme, 260000], [4, $blue, 180000], [6, $acme, 310000], [8, $blue, 170000], [10, $acme, 240000]] as [$m, $cust, $amt]) {
            $inv = $this->sale($cust, $this->at($this->P, $m, 6), [$this->line('Network installation', 1, $amt)]);
            $this->receive($cust, $this->at($this->P, $m, 25), $amt * 1.18, [$inv->id => $amt * 1.18]);
        }
        $this->sale($ever, $this->at($this->P, 9, 4), [$this->line('Annual support contract', 1, 30000)]);   // never collected → ages past 90 days

        // current year
        $a1 = $this->sale($acme, $this->at($this->S, 0, 5), [$this->line('Router X200', 40, 6500, 18, '8517', $router), $this->line('Installation', 1, 40000)]);
        $this->receive($acme, $this->at($this->S, 0, 28), $a1->total, [$a1->id => $a1->total]);                                              // paid in full
        $a2 = $this->sale($acme, $this->at($this->S, 1, 10), [$this->line('Cat6 Cable (305m)', 30, 7200, 18, '8544', $cable)]);
        $this->receive($acme, $this->at($this->S, 1, 28), round($a2->total * 0.4, 2), [$a2->id => round($a2->total * 0.4, 2)]);              // partly paid
        $this->sale($acme, $this->ago(50), [$this->line('Consulting Hour Pack', 80, 2500, 18, '9983', $consult)]);                          // unpaid, overdue

        $b1 = $this->sale($blue, $this->at($this->S, 1, 15), [$this->line('Managed services', 1, 180000)]);
        $this->receive($blue, $this->at($this->S, 2, 2), $b1->total, [$b1->id => $b1->total]);
        $b2 = $this->sale($blue, $this->at($this->S, 2, 12), [$this->line('Router X200', 20, 6500, 18, '8517', $router)]);
        $this->v(['type' => 'credit_note', 'date' => $this->at($this->S, 2, 20), 'party_ledger_id' => $blue->id, 'items' => [$this->line('Router X200 returned', 1, 6500, 18, '8517', $router)],
            'allocations' => [['voucher_id' => $b2->id, 'amount' => 7670]]], 'credit_notes');
        $b3 = $this->sale($blue, $this->ago(10), [$this->line('Managed services', 1, 90000)]);                                                // due in 5 days
        $this->receive($blue, $this->ago(8), 20000, [$b3->id => 20000]);                                                                    // part-paid, not yet late

        $adv = $this->receive($coastal, $this->at($this->S, 2, 3), 25000, [], ['is_advance' => true, 'narration' => 'Advance against catering software order']);
        $c1 = $this->sale($coastal, $this->at($this->S, 3, 18), [$this->line('POS configuration', 1, 40000)]);
        $this->pay->applyAdvance($this->cid, $this->uid, $adv->id, $c1->id, 25000, $this->at($this->S, 3, 19)); $this->count('advances_applied');
        $this->receive($coastal, $this->at($this->S, 4, 5), 10000, [$c1->id => 10000]);

        $this->sale($delta, $this->at($this->S, 3, 4), [$this->line('Site network audit', 1, 450000)], ['place_of_supply' => '27', 'credit_override' => true]);    // interstate → IGST; knowingly over the limit set below
        $this->receive($delta, $this->at($this->S, 4, 9), 60000);                                                                           // unallocated: stays on account
        $this->sale($ever, $this->at($this->S, 0, 2), [$this->line('Quarterly support', 1, 22000)]);

        // quotations — every status
        $q = fn (AccLedger $c, int $daysAgo, int $valid, string $subj, array $lines) => $this->quotes->save($this->cid, $this->uid, ['date' => $this->ago($daysAgo), 'valid_until' => $this->ago($daysAgo - $valid),
            'party_ledger_id' => $c->id, 'subject' => $subj, 'terms' => 'Payment within 15 days. Prices valid for the stated period.', 'notes' => 'Thank you for your business.', 'items' => $lines]);
        $q($acme, 0, 15, 'Office Wi-Fi upgrade', [$this->line('Access points', 6, 8500, 18, '8517'), $this->line('Setup', 1, 12000)]); $this->count('quotations');
        $sent = $q($blue, 3, 15, 'Annual maintenance', [$this->line('AMC', 1, 60000)]); $this->quotes->setStatus($this->cid, $sent->id, 'sent'); $this->count('quotations');
        $acc = $q($coastal, 6, 20, 'Kitchen display system', [$this->line('KDS units', 3, 14000, 18, '8528')]); $this->quotes->setStatus($this->cid, $acc->id, 'accepted'); $this->count('quotations');
        $rej = $q($delta, 12, 30, 'Data-centre cabling', [$this->line('Structured cabling', 1, 250000)]); $this->quotes->setStatus($this->cid, $rej->id, 'rejected'); $this->count('quotations');
        $q($ever, 60, 15, 'CCTV package', [$this->line('CCTV 8-camera kit', 1, 42000, 18, '8525')]); $this->count('quotations');                // expired
        $won = $q($acme, 20, 30, 'Server room cooling', [$this->line('Precision AC', 1, 95000, 18, '8415')]); $this->count('quotations');
        $this->quotes->convert($this->cid, $this->uid, $won->id, ['date' => $this->ago(18)]); $this->count('quotations_converted');
    }

    private function purchasesAndExpenses(): void
    {
        [$tech, $paper, $law] = [$this->s['tech'], $this->s['paper'], $this->s['law']];
        $router = $this->it['HW-200']; $cable = $this->it['HW-CAB']; $a4 = $this->it['ST-A4'];

        foreach ([[3, 200000], [7, 150000], [11, 180000]] as [$m, $amt]) {
            $b = $this->bill($tech, $this->at($this->P, $m, 8), [$this->line('Networking stock', 1, $amt, 18, '8517')]);
            $this->payTo($tech, $this->at($this->P, $m, 28), $b->total, [$b->id => $b->total]);
        }
        $t1 = $this->bill($tech, $this->at($this->S, 0, 8), [$this->line('Router X200', 45, 4800, 18, '8517', $router), $this->line('Cat6 Cable (305m)', 35, 5600, 18, '8544', $cable)], ['reference_no' => 'TS/2026/0412']);
        $this->payTo($tech, $this->at($this->S, 1, 3), $t1->total, [$t1->id => $t1->total]);
        $t2 = $this->bill($tech, $this->at($this->S, 3, 6), [$this->line('Router X200', 30, 4800, 18, '8517', $router)], ['reference_no' => 'TS/2026/0733']);   // unpaid, overdue
        $this->v(['type' => 'debit_note', 'date' => $this->at($this->S, 3, 15), 'party_ledger_id' => $tech->id, 'items' => [$this->line('Faulty routers returned', 2, 4800, 18, '8517', $router)],
            'allocations' => [['voucher_id' => $t2->id, 'amount' => 11328]]], 'debit_notes');
        $p1 = $this->bill($paper, $this->ago(20), [$this->line('A4 Paper Ream', 100, 240, 12, '4802', $a4)]);                                  // recent, open

        // professional fees with TDS (194J @10%), part-paid; then TDS deposited to government
        $l1 = $this->v(['type' => 'expense', 'date' => $this->at($this->S, 1, 12), 'party_ledger_id' => $law->id, 'ledger_id' => $this->L('Professional & Legal Fees'), 'amount' => 40000,
            'gst_rate' => 18, 'tds_section' => '194J', 'tds_rate' => 10, 'description' => 'Company law retainer'], 'tds_bills');
        $this->payTo($law, $this->at($this->S, 2, 1), 20000, [$l1->id => 20000]);
        $this->payTo(AccLedger::find($this->sys('tds_payable')), $this->at($this->S, 2, 7), 4000, [], ['narration' => 'TDS 194J deposited']);

        // recurring costs, both years
        $loop = function (Carbon $base, int $months) {
            for ($m = 0; $m < $months; $m++) {
                if ($base->copy()->addMonthsNoOverflow($m)->gt($this->today)) { break; }
                $this->spend('Office Rent', $this->at($base, $m, 1), 25000, 'bank', 'Office rent');
                $this->spend('Electricity & Water', $this->at($base, $m, 12), 3200 + 150 * ($m % 4), 'bank');
                $this->spend('Internet & Phone', $this->at($base, $m, 15), 2400, 'bank', 'Fibre + mobile', 18);
                if ($m % 3 === 0) { $this->spend('Marketing & Advertising', $this->at($base, $m, 20), 18000, 'bank', 'Digital campaign', 18); }
            }
        };
        $loop($this->P, 12); $loop($this->S, 12);
        $this->spend('Bank Charges', $this->at($this->S, 1, 30), 450, 'bank');
        $this->spend('Software & Subscriptions', $this->at($this->S, 2, 9), 12000, 'bank', 'Cloud hosting', 18);
        $this->spend('Interest Expense', $this->at($this->S, 3, 28), 7500, 'bank', 'Term-loan interest');
        $this->v(['type' => 'income', 'date' => $this->at($this->S, 4, 5), 'ledger_id' => $this->sys('interest_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 3800, 'description' => 'Fixed-deposit interest'], 'income');
        $this->v(['type' => 'income', 'date' => $this->at($this->S, 2, 22), 'ledger_id' => $this->sys('other_income'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 6000, 'description' => 'Scrap sale'], 'income');
        $this->v(['type' => 'contra', 'date' => $this->at($this->S, 1, 2), 'ledger_id' => $this->sys('cash'), 'account_ledger_id' => $this->sys('bank'), 'amount' => 20000, 'narration' => 'Cash withdrawn for office'], 'contra');
        unset($p1);
    }

    private function adjustingEntries(): void
    {
        $rent = $this->L('Office Rent'); $elec = $this->L('Electricity & Water');
        $mEnd = $this->today->copy()->subMonthNoOverflow()->endOfMonth();
        if ($mEnd->lt($this->S)) { $mEnd = $this->S->copy()->addDays(10); }
        $mEnd = $mEnd->gt($this->today) ? $this->today->copy() : $mEnd;
        $d = fn ($c) => $c->toDateString();
        $this->journals->adjusting($this->cid, $this->uid, ['kind' => 'accrued_expense', 'date' => $d($mEnd), 'amount' => 4100, 'ledger_id' => $elec, 'auto_reverse' => true, 'narration' => 'Electricity for the month, bill awaited']); $this->count('adjusting');
        $this->journals->adjusting($this->cid, $this->uid, ['kind' => 'prepaid_payment', 'date' => $this->at($this->S, 1, 1), 'amount' => 36000, 'account_ledger_id' => $this->sys('bank'), 'narration' => 'Annual insurance premium']); $this->count('adjusting');
        $this->journals->adjusting($this->cid, $this->uid, ['kind' => 'prepaid_amortize', 'date' => $this->at($this->S, 2, 1), 'amount' => 3000, 'ledger_id' => $this->L('Repairs & Maintenance'), 'narration' => 'Insurance — one month used']); $this->count('adjusting');
        $this->journals->adjusting($this->cid, $this->uid, ['kind' => 'unearned_receive', 'date' => $this->at($this->S, 2, 5), 'amount' => 30000, 'account_ledger_id' => $this->sys('bank'), 'narration' => '6-month support plan paid upfront']); $this->count('adjusting');
        $this->journals->adjusting($this->cid, $this->uid, ['kind' => 'unearned_recognize', 'date' => $this->at($this->S, 3, 5), 'amount' => 10000, 'ledger_id' => $this->sys('service_revenue'), 'narration' => 'Two months of support delivered']); $this->count('adjusting');
        $this->journals->postDueReversals($this->cid);
        unset($rent);
    }

    private function pettyCash(): void
    {
        $bank = $this->sys('bank');
        $this->petty->topUp($this->cid, $this->uid, ['date' => $this->at($this->S, 3, 1), 'from_ledger_id' => $bank, 'amount' => 5000]); $this->count('petty_topups');
        foreach ([['Travel & Conveyance', 650, 'Taxi to client site'], ['Office Supplies & Stationery', 480, 'Markers and files'], ['Miscellaneous Expenses', 1200, 'Pantry supplies'], ['Travel & Conveyance', 900, 'Courier charges']] as $i => [$h, $a, $desc]) {
            $this->petty->spend($this->cid, $this->uid, ['date' => $this->at($this->S, 3, 3 + $i * 4), 'ledger_id' => $this->L($h), 'amount' => $a, 'description' => $desc]); $this->count('petty_spends');
        }
        $this->petty->topUp($this->cid, $this->uid, ['date' => $this->ago(6), 'from_ledger_id' => $bank]); $this->count('petty_topups');   // replenish back to the float
    }

    private function payrollCycle(): void
    {
        $first = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(4);
        $mk = fn (array $x) => $this->payroll->saveEmployee($this->cid, $x + ['allowance' => 0, 'annual_ticket' => 0, 'leave_days_per_year' => 30, 'eos_days_per_year' => 21]);
        $mk(['name' => 'Anita Menon', 'code' => 'E001', 'designation' => 'Operations Manager', 'joined_on' => '2023-04-01', 'basic' => 30000, 'allowance' => 8000, 'annual_ticket' => 12000, 'leave_taken_days' => 12]);
        $mk(['name' => 'Rahul Nair', 'code' => 'E002', 'designation' => 'Network Engineer', 'joined_on' => '2024-09-15', 'basic' => 22000, 'allowance' => 4000, 'annual_ticket' => 8000]);
        $mk(['name' => 'Divya Pillai', 'code' => 'E003', 'designation' => 'Accountant', 'joined_on' => '2025-02-01', 'basic' => 18000, 'allowance' => 3000]);
        $mk(['name' => 'Sameer Khan', 'code' => 'E004', 'designation' => 'Sales Executive', 'joined_on' => $first->copy()->day(16)->toDateString(), 'basic' => 15000, 'allowance' => 2000]);   // joins mid-month → pro-rated
        $left = $mk(['name' => 'Joseph Mathew', 'code' => 'E005', 'designation' => 'Technician', 'joined_on' => '2024-02-01', 'basic' => 17000, 'allowance' => 2000, 'annual_ticket' => 6000,
            'left_on' => $first->copy()->addMonthsNoOverflow(2)->endOfMonth()->toDateString()]);
        $this->n['employees'] = 5;

        $bank = $this->sys('bank');
        for ($i = 0; $i < 4; $i++) {
            $period = $first->copy()->addMonthsNoOverflow($i)->format('Y-m');
            $run = $this->payroll->createRun($this->cid, $this->uid, $period);
            $line = $run->lines->firstWhere('employee_id', $left->id) ?: $run->lines->first();
            if ($i === 1 && $line) { $this->payroll->updateLine($this->cid, $run->id, $line->id, ['commission' => 3500, 'unpaid_days' => 2, 'other_deductions' => 500, 'notes' => 'Sales commission; 2 unpaid days']); }
            $this->payroll->post($this->cid, $this->uid, $run->id); $this->count('payroll_runs');
            if ($i < 3) { $this->payroll->pay($this->cid, $this->uid, $run->id, $bank, $this->at($first->copy()->addMonthsNoOverflow($i + 1), 0, 1)); }
            if ($i === 2) { $this->payroll->settle($this->cid, $this->uid, $left->id, ['account_ledger_id' => $bank]); $this->count('settlements'); }
        }
    }

    private function assetsDepreciationAndDisposal(): void
    {
        $this->assets->run($this->cid, $this->uid); $this->count('depreciation_runs');
        $this->assets->create($this->cid, $this->uid, ['name' => 'Rack Server', 'category' => 'equipment', 'purchase_date' => $this->at($this->S, 1, 12), 'cost' => 185000, 'salvage_value' => 15000,
            'useful_life_months' => 48, 'method' => 'sl', 'paid_from_ledger_id' => $this->sys('bank'), 'location' => 'Server room']); $this->count('assets');
        $this->assets->run($this->cid, $this->uid);
        $printer = \App\Modules\Accounts\Models\AccAsset::where('company_id', $this->cid)->where('name', 'Old Laser Printer')->first();
        $date = $this->today->copy()->subMonthNoOverflow()->day(min(15, $this->today->copy()->subMonthNoOverflow()->daysInMonth))->toDateString();
        if ($printer && $date >= $printer->purchase_date->toDateString()) {
            $this->assets->dispose($this->cid, $this->uid, $printer->id, ['date' => $date, 'proceeds' => 4000, 'account_ledger_id' => $this->sys('bank')]); $this->count('asset_disposals');
        }
    }

    private function creditTermsAndReconcile(): void
    {
        $set = fn (string $k, array $x) => $this->c[$k]->update($x);
        $set('acme', ['credit_limit' => 500000]);
        $set('blue', ['credit_limit' => 150000]);
        $set('coastal', ['credit_limit' => 100000]);
        $set('delta', ['credit_limit' => 100000]);                            // already owes more than this → "over limit"
        $set('ever', ['credit_limit' => 25000, 'credit_hold' => true]);       // slow payer, on hold

        // bank statement: everything older than 45 days has cleared
        $ids = DB::table('acc_entries')->where('company_id', $this->cid)->where('ledger_id', $this->sys('bank'))->where('date', '<=', $this->ago(45))->whereNull('reconciled_on')->pluck('id')->all();
        if ($ids) { $this->svc->reconcile($this->cid, $ids, $this->ago(44)); $this->n['bank_entries_reconciled'] = count($ids); }
    }

    private function closePreviousYear(): void
    {
        $this->assets->run($this->cid, $this->uid);
        $this->fy->ensure($this->cid);
        $prev = AccFiscalYear::where('company_id', $this->cid)->where('end_date', '<', $this->S->toDateString())->orderByDesc('start_date')->first();
        if ($prev) { $this->fy->close($this->cid, $this->uid, $prev->id); $this->n['fiscal_years_closed'] = 1; }
    }
}
