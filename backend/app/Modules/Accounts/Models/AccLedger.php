<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccLedger extends Model
{
    protected $table = 'acc_ledgers';
    protected $fillable = [
        'company_id', 'name', 'nature', 'group', 'opening_balance', 'opening_type', 'gstin', 'state_code',
        'phone', 'email', 'address', 'pincode', 'city', 'pan', 'system_key', 'is_active', 'code', 'credit_limit', 'credit_days', 'credit_hold',
    ];
    protected $casts = ['opening_balance' => 'float', 'is_active' => 'boolean', 'credit_limit' => 'float', 'credit_days' => 'integer', 'credit_hold' => 'boolean'];

    /** Assets & expenses carry a debit balance; liabilities, income & equity a credit balance. */
    public function isDebitNature(): bool
    {
        return in_array($this->nature, ['asset', 'expense'], true);
    }
}
