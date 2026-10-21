<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccEntry extends Model
{
    protected $table = 'acc_entries';
    public $timestamps = false;
    protected $fillable = ['company_id', 'voucher_id', 'ledger_id', 'date', 'debit', 'credit', 'narration', 'reconciled_on'];
    protected $casts = ['date' => 'date:Y-m-d', 'reconciled_on' => 'date:Y-m-d', 'debit' => 'float', 'credit' => 'float'];

    public function voucher(): BelongsTo { return $this->belongsTo(AccVoucher::class, 'voucher_id'); }
    public function ledger(): BelongsTo  { return $this->belongsTo(AccLedger::class, 'ledger_id'); }
}
