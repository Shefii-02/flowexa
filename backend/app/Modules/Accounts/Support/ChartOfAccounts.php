<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccSetting;

/**
 * The standard chart of accounts: 1000s assets, 2000s liabilities, 3000s equity, 4000s revenue, 5000s expenses.
 * Party ledgers (customers 1200s, suppliers 2100s) are numbered automatically as they are created.
 */
class ChartOfAccounts
{
    /** Bump when DEFAULTS gains entries — every company is topped up the next time it opens Accounts. */
    public const VERSION = 2;

    // [code, name, nature, group, system_key]
    public const DEFAULTS = [
        // 1000s — Assets (current, then fixed)
        ['1010', 'Cash in Hand', 'asset', 'cash', 'cash'],
        ['1020', 'Petty Cash', 'asset', 'cash', 'petty_cash'],
        ['1100', 'Bank Account', 'asset', 'bank', 'bank'],
        ['1300', 'Inventory', 'asset', 'inventory', 'inventory'],
        ['1400', 'Prepaid Expenses', 'asset', 'prepaid', 'prepaid'],
        ['1410', 'Advances to Suppliers', 'asset', 'prepaid', 'supplier_advances'],
        ['1510', 'Input CGST', 'asset', 'gst_input', 'in_cgst'],
        ['1520', 'Input SGST', 'asset', 'gst_input', 'in_sgst'],
        ['1530', 'Input IGST', 'asset', 'gst_input', 'in_igst'],
        ['1610', 'Land & Buildings', 'asset', 'fixed_asset', 'property'],
        ['1620', 'Plant, Machinery & Equipment', 'asset', 'fixed_asset', 'equipment'],
        ['1630', 'Vehicles', 'asset', 'fixed_asset', 'vehicles'],
        ['1640', 'Intangible Assets', 'asset', 'intangible', 'intangibles'],
        ['1690', 'Accumulated Depreciation', 'asset', 'accum_depreciation', 'accum_depreciation'],
        // 2000s — Liabilities
        ['2200', 'Short-Term Loans', 'liability', 'loan', 'st_loan'],
        ['2300', 'Accrued Expenses', 'liability', 'accrued', 'accrued_expenses'],
        ['2310', 'Salary Payable', 'liability', 'payroll', 'salary_payable'],
        ['2315', 'Payroll Deductions Payable', 'liability', 'payroll', 'payroll_deductions'],
        ['2320', 'TDS Payable', 'liability', 'tds', 'tds_payable'],
        ['2330', 'Output CGST', 'liability', 'gst_output', 'out_cgst'],
        ['2340', 'Output SGST', 'liability', 'gst_output', 'out_sgst'],
        ['2350', 'Output IGST', 'liability', 'gst_output', 'out_igst'],
        ['2400', 'Customer Advances (Deferred Revenue)', 'liability', 'deferred_revenue', 'deferred_revenue'],
        ['2405', 'Unearned Revenue', 'liability', 'deferred_revenue', 'unearned_revenue'],
        ['2410', 'Leave Salary Provision', 'liability', 'provision', 'leave_provision'],
        ['2420', 'Annual Ticket Provision', 'liability', 'provision', 'ticket_provision'],
        ['2500', 'Long-Term Loans', 'liability', 'long_term_loan', 'lt_loan'],
        ['2600', 'Mortgage Payable', 'liability', 'long_term_loan', 'mortgage'],
        ['2700', 'End of Service Provision', 'liability', 'eos_provision', 'eos_provision'],
        // 3000s — Equity
        ['3100', 'Capital Account', 'equity', 'capital', 'capital'],
        ['3200', 'Retained Earnings', 'equity', 'retained', 'retained'],
        ['3300', 'Drawings', 'equity', 'capital', 'drawings'],
        // 4000s — Revenue
        ['4100', 'Sales', 'income', 'sales', 'sales'],
        ['4200', 'Service Revenue', 'income', 'direct_income', 'service_revenue'],
        ['4300', 'Interest Income', 'income', 'non_operating_income', 'interest_income'],
        ['4900', 'Other Income', 'income', 'indirect_income', 'other_income'],
        // 5000s — Expenses
        ['5100', 'Purchases', 'expense', 'purchase', 'purchase'],
        ['5110', 'Cost of Goods Sold', 'expense', 'direct_expense', 'cogs'],
        ['5200', 'Office Rent', 'expense', 'indirect_expense', null],
        ['5210', 'Electricity & Water', 'expense', 'indirect_expense', null],
        ['5220', 'Internet & Phone', 'expense', 'indirect_expense', null],
        ['5230', 'Email & Domain', 'expense', 'indirect_expense', null],
        ['5240', 'Telephone & Mobile', 'expense', 'indirect_expense', null],
        ['5300', 'Salaries & Wages', 'expense', 'indirect_expense', 'salaries'],
        ['5310', 'Commission', 'expense', 'indirect_expense', 'commission'],
        ['5320', 'Leave Salary Expense', 'expense', 'indirect_expense', 'leave_expense'],
        ['5330', 'Annual Ticket Expense', 'expense', 'indirect_expense', 'ticket_expense'],
        ['5340', 'End of Service Expense', 'expense', 'indirect_expense', 'eos_expense'],
        ['5400', 'Marketing & Advertising', 'expense', 'indirect_expense', null],
        ['5410', 'Travel & Conveyance', 'expense', 'indirect_expense', null],
        ['5420', 'Office Supplies & Stationery', 'expense', 'indirect_expense', null],
        ['5430', 'Repairs & Maintenance', 'expense', 'indirect_expense', null],
        ['5440', 'Professional & Legal Fees', 'expense', 'indirect_expense', null],
        ['5450', 'Software & Subscriptions', 'expense', 'indirect_expense', null],
        ['5460', 'Bank Charges', 'expense', 'indirect_expense', null],
        ['5490', 'Miscellaneous Expenses', 'expense', 'indirect_expense', null],
        ['5500', 'Depreciation Expense', 'expense', 'indirect_expense', 'depreciation'],
        ['5600', 'Interest Expense', 'expense', 'non_operating_expense', 'interest_expense'],
        ['5610', 'Gain / Loss on Asset Disposal', 'expense', 'non_operating_expense', 'disposal_gain_loss'],
    ];

    /** Where auto-numbered ledgers live, by group; anything else falls back to its nature's thousand. */
    private const RANGES = ['debtor' => [1200, 1299], 'creditor' => [2100, 2199]];
    private const NATURE_BASE = ['asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'income' => 4000, 'expense' => 5000];

    /** Name and code of a default by system key. */
    public static function def(string $key): ?array
    {
        foreach (self::DEFAULTS as $d) { if ($d[4] === $key) { return $d; } }
        return null;
    }

    /**
     * Idempotent: adds any default ledger the company is missing, tags a same-named existing ledger with its
     * system key instead of duplicating it, and numbers ledgers that have no code yet.
     */
    public static function ensure(int $cid): void
    {
        $set = AccSetting::firstOrCreate(['company_id' => $cid]);
        if ($set->chart_version >= self::VERSION) { return; }

        foreach (self::DEFAULTS as $d) { self::ensureLedger($cid, $d); }
        // Existing custom ledgers get a code too, so the chart can be read in order.
        foreach (AccLedger::where('company_id', $cid)->whereNull('code')->orderBy('id')->get() as $l) {
            $l->update(['code' => self::nextCode($cid, $l->nature, $l->group)]);
        }
        $set->update(['chart_version' => self::VERSION]);
    }

    /** Find-or-create one default ledger. Returns its id. */
    public static function ensureLedger(int $cid, array $d): int
    {
        [$code, $name, $nature, $group, $key] = $d;
        $q = AccLedger::where('company_id', $cid);
        $l = $key ? (clone $q)->where('system_key', $key)->first() : null;
        $l ??= (clone $q)->where('name', $name)->first();
        if ($l) {
            $upd = [];
            if ($key && !$l->system_key) { $upd['system_key'] = $key; }
            if (!$l->code && !(clone $q)->where('code', $code)->exists()) { $upd['code'] = $code; }
            if ($upd) { $l->update($upd); }
            return $l->id;
        }
        $taken = (clone $q)->where('code', $code)->exists();
        return AccLedger::create(['company_id' => $cid, 'name' => $name, 'nature' => $nature, 'group' => $group, 'system_key' => $key,
                                  'code' => $taken ? self::nextCode($cid, $nature, $group) : $code])->id;
    }

    /** Next free account code for a new ledger (null if its range is full). */
    public static function nextCode(int $cid, string $nature, string $group): ?string
    {
        [$lo, $hi] = self::RANGES[$group] ?? [self::NATURE_BASE[$nature] ?? 5000, ($nature ? (self::NATURE_BASE[$nature] ?? 5000) : 5000) + 999];
        $step = isset(self::RANGES[$group]) ? 1 : 10;
        $used = AccLedger::where('company_id', $cid)->whereNotNull('code')->pluck('code')->map(fn ($c) => (int) $c)->filter(fn ($c) => $c >= $lo && $c <= $hi);
        $n = $used->isEmpty() ? $lo + ($step === 1 ? 1 : 700) : $used->max() + $step; // custom ledgers start at x700, clear of the defaults
        if ($step === 10 && $n < $lo + 700) { $n = $lo + 700; }
        if ($n > $hi) { // range exhausted at the top — take the first gap
            for ($c = $lo; $c <= $hi; $c += $step) { if (!$used->contains($c)) { return (string) $c; } }
            return null;
        }
        return (string) $n;
    }
}
