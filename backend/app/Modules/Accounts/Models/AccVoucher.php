<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccVoucher extends Model
{
    protected $table = 'acc_vouchers';
    protected $fillable = [
        'company_id', 'type', 'number', 'date', 'party_ledger_id', 'ledger_id', 'account_ledger_id', 'place_of_supply',
        'is_interstate', 'taxable', 'cgst', 'sgst', 'igst', 'total', 'tds_section', 'tds_rate', 'tds_amount', 'reference_no', 'narration', 'attachment',
        'is_cancelled', 'created_by',
    ];
    protected $casts = [
        'date' => 'date:Y-m-d', 'is_interstate' => 'boolean', 'is_cancelled' => 'boolean',
        'taxable' => 'float', 'cgst' => 'float', 'sgst' => 'float', 'igst' => 'float', 'total' => 'float', 'tds_rate' => 'float', 'tds_amount' => 'float',
    ];

    public function items(): HasMany   { return $this->hasMany(AccVoucherItem::class, 'voucher_id'); }
    public function entries(): HasMany { return $this->hasMany(AccEntry::class, 'voucher_id'); }
    public function party(): BelongsTo { return $this->belongsTo(AccLedger::class, 'party_ledger_id'); }
    public function ledger(): BelongsTo { return $this->belongsTo(AccLedger::class, 'ledger_id'); }
    public function account(): BelongsTo { return $this->belongsTo(AccLedger::class, 'account_ledger_id'); }
}
