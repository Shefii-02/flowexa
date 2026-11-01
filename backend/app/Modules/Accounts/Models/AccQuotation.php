<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccQuotation extends Model
{
    protected $table = 'acc_quotations';
    protected $fillable = [
        'company_id', 'number', 'date', 'valid_until', 'party_ledger_id', 'place_of_supply', 'is_interstate', 'status',
        'taxable', 'cgst', 'sgst', 'igst', 'total', 'subject', 'notes', 'terms', 'converted_voucher_id', 'created_by',
    ];
    protected $casts = [
        'date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'is_interstate' => 'boolean',
        'taxable' => 'float', 'cgst' => 'float', 'sgst' => 'float', 'igst' => 'float', 'total' => 'float',
    ];
    protected $appends = ['effective_status'];

    /** Draft/sent offers past their validity date read as expired without any scheduled job. */
    public function getEffectiveStatusAttribute(): string
    {
        return in_array($this->status, ['draft', 'sent'], true) && $this->valid_until && $this->valid_until->lt(now()->startOfDay()) ? 'expired' : $this->status;
    }

    public function items(): HasMany   { return $this->hasMany(AccQuotationItem::class, 'quotation_id'); }
    public function party(): BelongsTo { return $this->belongsTo(AccLedger::class, 'party_ledger_id'); }
}
