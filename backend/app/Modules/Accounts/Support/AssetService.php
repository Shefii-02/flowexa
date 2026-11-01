<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccAsset;
use App\Modules\Accounts\Models\AccAssetDepreciation;
use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixed-asset register: property, equipment, vehicles, intangibles.
 * Depreciation is charged per completed month, starting with the month of purchase, and posted as one journal per month
 * (Dr Depreciation Expense / Cr Accumulated Depreciation). A disposal clears the asset's cost and accumulated depreciation
 * and books the gain or loss to Non-Operating Items.
 */
class AssetService
{
    public const CATEGORIES = ['property' => 'property', 'equipment' => 'equipment', 'vehicle' => 'vehicles', 'intangible' => 'intangibles']; // → system ledger key

    public function __construct(private readonly AccountsService $svc) {}

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    public function find(int $cid, int $id): AccAsset
    {
        return AccAsset::where('company_id', $cid)->findOrFail($id);
    }

    // ─── Register ─────────────────────────────────────────────────────────────
    public function create(int $cid, ?int $userId, array $d): AccAsset
    {
        $this->svc->seedDefaults($cid);
        $cost = round((float) $d['cost'], 2); $salvage = round((float) ($d['salvage_value'] ?? 0), 2);
        if ($cost <= 0) { $this->fail('cost', 'Enter the purchase cost.'); }
        if ($salvage < 0 || $salvage >= $cost) { $this->fail('salvage_value', 'Salvage value must be less than the cost.'); }
        $method = $d['method'] ?? 'sl';
        if ($method === 'wdv' && (($d['category'] ?? '') === 'intangible')) { $this->fail('method', 'Intangible assets are amortised on a straight-line basis.'); }
        if ($method === 'wdv' && (float) ($d['wdv_rate'] ?? 0) <= 0) { $this->fail('wdv_rate', 'Enter the yearly depreciation rate.'); }
        $opening = round((float) ($d['opening_accumulated'] ?? 0), 2);
        if ($opening < 0 || $opening > $cost - $salvage + 0.004) { $this->fail('opening_accumulated', 'Depreciation already charged cannot exceed cost less salvage value.'); }
        $mode = $d['acquisition'] ?? 'purchase';
        if ($mode === 'purchase' && empty($d['paid_from_ledger_id'])) { $this->fail('paid_from_ledger_id', 'Choose the cash/bank/supplier account it was paid from — or mark it as an existing asset.'); }
        if ($mode === 'purchase' && $opening > 0) { $this->fail('opening_accumulated', 'A newly purchased asset has no earlier depreciation.'); }
        if ($mode === 'existing' && !empty($d['depreciated_upto']) && Carbon::parse($d['depreciated_upto'])->lt(Carbon::parse($d['purchase_date']))) {
            $this->fail('depreciated_upto', 'That date is before the purchase date.');
        }

        $openingTo = $mode === 'existing' && !empty($d['depreciated_upto']) ? Carbon::parse($d['depreciated_upto'])->endOfMonth()->toDateString() : null;

        return DB::transaction(function () use ($cid, $userId, $d, $cost, $salvage, $method, $opening, $mode, $openingTo) {
            $voucherId = null;
            if ($mode === 'purchase') {
                $paid = AccLedger::where('company_id', $cid)->find($d['paid_from_ledger_id']);
                if (!$paid) { $this->fail('paid_from_ledger_id', 'That account does not exist.'); }
                $voucherId = $this->svc->saveVoucher($cid, $userId, [
                    'type' => 'journal', 'date' => $d['purchase_date'], 'sub_type' => 'asset', 'source_type' => 'asset',
                    'entries' => [['ledger_id' => $this->svc->sys($cid, self::CATEGORIES[$d['category']]), 'debit' => $cost], ['ledger_id' => $paid->id, 'credit' => $cost]],
                    'narration' => "Purchase of {$d['name']}",
                ])->id;
            }
            $asset = AccAsset::create([
                'company_id' => $cid, 'tag' => $d['tag'] ?? null, 'name' => $d['name'], 'category' => $d['category'], 'purchase_date' => $d['purchase_date'],
                'cost' => $cost, 'salvage_value' => $salvage, 'useful_life_months' => (int) ($d['useful_life_months'] ?? 60), 'method' => $method,
                'wdv_rate' => $method === 'wdv' ? $d['wdv_rate'] : null, 'opening_accumulated' => $opening, 'accumulated' => $opening,
                'opening_to' => $openingTo, 'last_depreciated_to' => $openingTo,
                'acquisition_voucher_id' => $voucherId, 'location' => $d['location'] ?? null, 'notes' => $d['notes'] ?? null,
            ]);
            if ($voucherId) { AccVoucher::whereKey($voucherId)->update(['source_id' => $asset->id]); }
            return $asset;
        });
    }

    /** Descriptive fields can always change; money fields only until the first depreciation or disposal. */
    public function update(int $cid, int $id, array $d): AccAsset
    {
        $a = $this->find($cid, $id);
        $free = array_intersect_key($d, array_flip(['tag', 'name', 'location', 'notes']));
        $moneyChanged = collect(['cost', 'salvage_value', 'useful_life_months', 'method', 'wdv_rate', 'purchase_date', 'category'])
            ->contains(fn ($k) => array_key_exists($k, $d) && (string) $d[$k] !== (string) ($a->$k instanceof Carbon ? $a->$k->toDateString() : $a->$k));
        if ($moneyChanged) {
            if ($a->status === 'disposed' || $a->depreciations()->exists() || $a->last_depreciated_to) {
                $this->fail('cost', 'Depreciation has already been posted for this asset — only its name, tag, location and notes can change.');
            }
            if ($a->acquisition_voucher_id) { $this->fail('cost', 'Delete the asset and re-enter it to change what was paid — its purchase entry is already in the books.'); }
            $free += array_intersect_key($d, array_flip(['cost', 'salvage_value', 'useful_life_months', 'method', 'wdv_rate', 'purchase_date', 'category']));
        }
        $a->update($free);
        return $a->fresh();
    }

    public function delete(int $cid, int $id): void
    {
        $a = $this->find($cid, $id);
        if ($a->status === 'disposed' || $a->depreciations()->exists()) { $this->fail('asset', 'This asset has depreciation or disposal entries — it can no longer be deleted.'); }
        DB::transaction(function () use ($cid, $a) {
            if ($a->acquisition_voucher_id && ($v = AccVoucher::where('company_id', $cid)->find($a->acquisition_voucher_id))) { $this->svc->deleteVoucher($cid, $v); }
            $a->delete();
        });
    }

    // ─── Depreciation ─────────────────────────────────────────────────────────
    /** First month-end this asset still needs charging for. */
    public function nextPeriod(AccAsset $a): Carbon
    {
        return $a->last_depreciated_to
            ? Carbon::parse($a->last_depreciated_to)->addMonthNoOverflow()->endOfMonth()->startOfDay()
            : Carbon::parse($a->purchase_date)->endOfMonth()->startOfDay();
    }

    public function monthlyCharge(AccAsset $a): float
    {
        $remaining = round($a->cost - $a->salvage_value - $a->accumulated, 2);
        if ($remaining <= 0) { return 0.0; }
        $m = $a->method === 'wdv'
            ? round(($a->cost - $a->accumulated) * ((float) $a->wdv_rate) / 100 / 12, 2)
            : round(($a->cost - $a->salvage_value) / max(1, $a->useful_life_months), 2);
        return round(($remaining - $m < 0.01) ? $remaining : $m, 2); // clear the last few paise rather than leave a stub
    }

    /** Last month-end that has fully passed. */
    public function lastCompletedMonthEnd(?string $today = null): Carbon
    {
        $t = Carbon::parse($today ?? now()->toDateString())->startOfDay();
        return $t->copy()->endOfMonth()->startOfDay()->eq($t) ? $t : $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay();
    }

    /** What a run up to $upto would post, per month — nothing is written. */
    public function previewRun(int $cid, ?string $upto = null): array
    {
        $limit = $this->lastCompletedMonthEnd();
        $to = $upto ? Carbon::parse($upto)->endOfMonth()->startOfDay() : $limit;
        if ($to->gt($limit)) { $to = $limit; }   // never depreciate a month that has not finished
        $months = [];
        foreach (AccAsset::where('company_id', $cid)->where('status', 'active')->orderBy('id')->get() as $a) {
            $sim = clone $a; $sim->accumulated = $a->accumulated;
            for ($p = $this->nextPeriod($a); $p->lte($to); $p = $p->copy()->addMonthNoOverflow()->endOfMonth()->startOfDay()) {
                $amt = $this->monthlyCharge($sim);
                if ($amt <= 0) { break; }
                $months[$p->toDateString()][] = ['asset_id' => $a->id, 'name' => $a->name, 'amount' => $amt];
                $sim->accumulated = round($sim->accumulated + $amt, 2);
            }
        }
        ksort($months);
        $out = [];
        foreach ($months as $period => $rows) { $out[] = ['period_end' => $period, 'assets' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)]; }
        return ['upto' => $to->toDateString(), 'months' => $out, 'total' => round(array_sum(array_column($out, 'total')), 2)];
    }

    public function run(int $cid, ?int $userId, ?string $upto = null): array
    {
        $this->svc->seedDefaults($cid);
        $plan = $this->previewRun($cid, $upto);
        if (!$plan['months']) { return ['posted' => 0, 'months' => [], 'total' => 0.0, 'upto' => $plan['upto']]; }

        return DB::transaction(function () use ($cid, $userId, $plan) {
            $exp = $this->svc->sys($cid, 'depreciation'); $acc = $this->svc->sys($cid, 'accum_depreciation');
            $done = [];
            foreach ($plan['months'] as $m) {
                $v = $this->svc->saveVoucher($cid, $userId, [
                    'type' => 'journal', 'date' => $m['period_end'], 'sub_type' => 'adjusting', 'source_type' => 'asset_dep',
                    'entries' => [['ledger_id' => $exp, 'debit' => $m['total']], ['ledger_id' => $acc, 'credit' => $m['total']]],
                    'narration' => 'Depreciation for ' . Carbon::parse($m['period_end'])->format('F Y'),
                ]);
                foreach ($m['assets'] as $r) {
                    AccAssetDepreciation::create(['company_id' => $cid, 'asset_id' => $r['asset_id'], 'period_end' => $m['period_end'], 'amount' => $r['amount'], 'voucher_id' => $v->id]);
                }
                $done[] = ['period_end' => $m['period_end'], 'total' => $m['total'], 'voucher_id' => $v->id];
            }
            foreach (array_unique(array_merge(...array_map(fn ($m) => array_column($m['assets'], 'asset_id'), $plan['months']))) as $id) { $this->refresh($id); }
            return ['posted' => count($done), 'months' => $done, 'total' => $plan['total'], 'upto' => $plan['upto']];
        });
    }

    /** Re-derive an asset's running totals from the charges actually on file. */
    public function refresh(int $assetId): void
    {
        $a = AccAsset::find($assetId);
        if (!$a) { return; }
        $rows = AccAssetDepreciation::where('asset_id', $assetId);
        $last = (clone $rows)->max('period_end');
        $a->update(['accumulated' => round($a->opening_accumulated + (float) (clone $rows)->sum('amount'), 2),
                    'last_depreciated_to' => $last ?: $a->opening_to]);
    }

    /** Undo the most recent month's charge. Only the latest month can be undone, so history never has a gap. */
    public function undoRun(int $cid, string $periodEnd): void
    {
        $period = Carbon::parse($periodEnd)->toDateString();
        $rows = AccAssetDepreciation::where('company_id', $cid)->where('period_end', $period)->get();
        if ($rows->isEmpty()) { $this->fail('period', 'No depreciation was posted for that month.'); }
        if (AccAssetDepreciation::where('company_id', $cid)->where('period_end', '>', $period)->exists()) { $this->fail('period', 'Undo the later months first.'); }
        if (AccAsset::where('company_id', $cid)->whereIn('id', $rows->pluck('asset_id'))->where('status', 'disposed')->exists()) { $this->fail('period', 'An asset in this run has since been disposed.'); }
        DB::transaction(function () use ($cid, $rows) {
            $ids = $rows->pluck('asset_id')->all();
            foreach ($rows->pluck('voucher_id')->unique() as $vid) { if ($vid && ($v = AccVoucher::where('company_id', $cid)->find($vid))) { $this->svc->deleteVoucher($cid, $v); } }
            AccAssetDepreciation::whereIn('id', $rows->pluck('id'))->delete();
            foreach ($ids as $id) { $this->refresh($id); }
        });
    }

    // ─── Disposal ─────────────────────────────────────────────────────────────
    public function dispose(int $cid, ?int $userId, int $id, array $d): AccAsset
    {
        $a = $this->find($cid, $id);
        if ($a->status === 'disposed') { $this->fail('asset', 'This asset has already been disposed.'); }
        $date = Carbon::parse($d['date'])->startOfDay();
        if ($date->lt($a->purchase_date)) { $this->fail('date', 'The disposal cannot be before the purchase date.'); }
        $proceeds = round((float) ($d['proceeds'] ?? 0), 2);
        if ($proceeds > 0 && empty($d['account_ledger_id'])) { $this->fail('account_ledger_id', 'Choose where the sale proceeds were received.'); }

        // Depreciation must be up to date through the month before the disposal month, so the gain/loss is measured on a correct book value.
        $mustReach = $date->copy()->startOfMonth()->subDay()->startOfDay();
        if ($this->monthlyCharge($a) > 0 && $this->nextPeriod($a)->lte($mustReach)) {
            $this->fail('date', 'Run depreciation up to ' . $mustReach->format('d M Y') . ' before disposing of this asset.');
        }

        return DB::transaction(function () use ($cid, $userId, $a, $d, $date, $proceeds) {
            $cost = $a->cost; $acc = $a->accumulated;
            $gain = round($proceeds + $acc - $cost, 2);   // + gain, − loss
            $entries = [['ledger_id' => $this->svc->sys($cid, self::CATEGORIES[$a->category]), 'credit' => $cost]];
            if ($acc > 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, 'accum_depreciation'), 'debit' => $acc]; }
            if ($proceeds > 0) { $entries[] = ['ledger_id' => (int) $d['account_ledger_id'], 'debit' => $proceeds]; }
            if ($gain > 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, 'disposal_gain_loss'), 'credit' => $gain]; }
            elseif ($gain < 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, 'disposal_gain_loss'), 'debit' => -$gain]; }
            $v = $this->svc->saveVoucher($cid, $userId, ['type' => 'journal', 'date' => $date->toDateString(), 'sub_type' => 'asset', 'source_type' => 'asset', 'source_id' => $a->id,
                'entries' => $entries, 'narration' => ($proceeds > 0 ? 'Sale' : 'Write-off') . " of {$a->name}" . ($gain >= 0 ? ' (gain ' : ' (loss ') . number_format(abs($gain), 2) . ')']);
            $a->update(['status' => 'disposed', 'disposed_on' => $date->toDateString(), 'disposal_proceeds' => $proceeds, 'disposal_gain_loss' => $gain, 'disposal_voucher_id' => $v->id]);
            return $a->fresh();
        });
    }

    // ─── Reporting ────────────────────────────────────────────────────────────
    public function register(int $cid): array
    {
        $rows = AccAsset::where('company_id', $cid)->orderBy('category')->orderBy('purchase_date')->get()->map(function ($a) {
            $nbv = $a->status === 'disposed' ? 0.0 : round($a->cost - $a->accumulated, 2);
            return $a->toArray() + ['net_book_value' => $nbv, 'monthly_charge' => $a->status === 'active' ? $this->monthlyCharge($a) : 0.0,
                'next_period' => $a->status === 'active' && $this->monthlyCharge($a) > 0 ? $this->nextPeriod($a)->toDateString() : null, 'fully_depreciated' => $a->status === 'active' && $this->monthlyCharge($a) <= 0];
        });
        $active = $rows->where('status', 'active');
        $ledger = $this->svc->balances($cid);
        $accLedger = -($ledger[$this->svc->sys($cid, 'accum_depreciation')]['balance'] ?? 0);
        return [
            'rows' => $rows->values(),
            'totals' => ['cost' => round($active->sum('cost'), 2), 'accumulated' => round($active->sum('accumulated'), 2), 'net_book_value' => round($active->sum('net_book_value'), 2)],
            'by_category' => $active->groupBy('category')->map(fn ($g, $k) => ['category' => $k, 'count' => $g->count(), 'cost' => round($g->sum('cost'), 2), 'net_book_value' => round($g->sum('net_book_value'), 2)])->values(),
            // The register and the ledger should agree; a gap means opening balances were keyed in on one side only.
            'ledger_check' => ['accumulated_in_ledger' => round($accLedger, 2), 'accumulated_in_register' => round($active->sum('accumulated'), 2),
                               'difference' => round($accLedger - $active->sum('accumulated'), 2)],
            'pending_depreciation' => $this->previewRun($cid)['total'],
        ];
    }
}
