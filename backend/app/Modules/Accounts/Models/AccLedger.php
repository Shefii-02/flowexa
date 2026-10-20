<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccLedger extends Model
{
    protected $table = 'acc_ledgers';
    protected $fillable = [
        'company_id', 'name', 'nature', 'group', 'opening_balance', 'opening_type', 'gstin', 'state_code',
        'phone', 'email', 'address', 'pan', 'system_key', 'is_active',
    ];
    protected $casts = ['opening_balance' => 'float', 'is_active' => 'boolean'];

    /** Assets & expenses carry a debit balance; liabilities, income & equity a credit balance. */
    public function isDebitNature(): bool
    {
        return in_array($this->nature, ['asset', 'expense'], true);
    }
}
