<?php

namespace App\Modules\Hr\Support;

use App\Models\Listing;
use App\Models\ListingSale;
use App\Modules\Hr\Models\HrIncentive;
use App\Modules\Hr\Models\HrIncentiveRule;
use Illuminate\Support\Facades\DB;

/**
 * Records catalog-item sales and posts the resulting staff incentive.
 *
 * Incentive resolution, in order:
 *   1. the listing's own `incentive_percentage` (per-item override), or
 *   2. an active HrIncentiveRule whose category matches the listing type, or
 *   3. any single active HrIncentiveRule (a company-wide default), or
 *   4. nothing — the sale is still recorded, incentive amount 0.
 */
class SalesService
{
    /** listing.type  ->  incentive rule category */
    private const CATEGORY_MAP = [
        'service'  => 'service',
        'course'   => 'course',
        'product'  => 'product',
        'property' => 'other',
    ];

    /**
     * @param array{
     *   listing_id?: int|null, lead_id?: int|null, contact_id?: int|null,
     *   staff_id: int, amount: float|int, currency?: string, sold_at: string,
     *   item_label?: string|null, note?: string|null, created_by?: int|null
     * } $data
     */
    public function record(int $companyId, array $data): ListingSale
    {
        return DB::transaction(function () use ($companyId, $data) {
            $listing = !empty($data['listing_id'])
                ? Listing::where('company_id', $companyId)->find($data['listing_id'])
                : null;

            $amount = round((float) $data['amount'], 2);

            $sale = ListingSale::create([
                'company_id' => $companyId,
                'listing_id' => $listing?->id,
                'lead_id'    => $data['lead_id']    ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'staff_id'   => $data['staff_id'],
                'item_label' => $data['item_label'] ?? $listing?->title,
                'amount'     => $amount,
                'currency'   => $data['currency'] ?? ($listing?->currency ?: 'INR'),
                'sold_at'    => $data['sold_at'],
                'note'       => $data['note'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            [$incentiveAmount, $rule] = $this->resolveIncentive($companyId, $listing, $amount);

            $incentive = HrIncentive::create([
                'company_id'        => $companyId,
                'user_id'           => $sale->staff_id,
                'incentive_rule_id' => $rule?->id,
                'source_type'       => 'listing_sale',
                'source_id'         => $sale->id,
                'title'             => 'Sale: ' . ($sale->item_label ?: 'catalog item'),
                'base_amount'       => $amount,
                'amount'            => round($incentiveAmount, 2),
                'earned_on'         => $sale->sold_at,
                'status'            => 'pending',
                'note'              => $data['note'] ?? null,
            ]);

            $sale->update(['incentive_id' => $incentive->id]);

            return $sale->fresh(['staff:id,name', 'listing:id,title', 'incentive']);
        });
    }

    /** Reverse a sale — removes its incentive unless already paid. */
    public function void(ListingSale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $incentive = $sale->incentive;
            if ($incentive) {
                if ($incentive->status === 'paid') {
                    abort(422, 'The incentive for this sale was already paid out — it cannot be voided.');
                }
                $incentive->delete();
            }
            $sale->update(['incentive_id' => null]);
            $sale->delete();
        });
    }

    /** @return array{0: float, 1: HrIncentiveRule|null} */
    private function resolveIncentive(int $companyId, ?Listing $listing, float $amount): array
    {
        // 1. Per-item override
        if ($listing && $listing->incentive_percentage !== null) {
            return [$amount * (float) $listing->incentive_percentage / 100, null];
        }

        $rules = HrIncentiveRule::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($rules->isEmpty()) {
            return [0.0, null];
        }

        // 2. Category-matched rule
        if ($listing) {
            $category = self::CATEGORY_MAP[$listing->type] ?? 'other';
            $match = $rules->firstWhere('category', $category);
            if ($match) {
                return [$match->amountFor($amount), $match];
            }
        }

        // 3. First active rule as a company-wide default
        $default = $rules->first();

        return [$default->amountFor($amount), $default];
    }
}
