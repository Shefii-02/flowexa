<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccAllocation extends Model
{
    protected $table = 'acc_allocations';
    protected $fillable = ['company_id', 'payment_voucher_id', 'invoice_voucher_id', 'amount', 'date', 'journal_voucher_id'];
    protected $casts = ['amount' => 'float', 'date' => 'date:Y-m-d'];
}
