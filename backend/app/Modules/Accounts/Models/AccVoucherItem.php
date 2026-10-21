<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccVoucherItem extends Model
{
    protected $table = 'acc_voucher_items';
    public $timestamps = false;
    protected $fillable = ['voucher_id', 'item_id', 'description', 'hsn', 'qty', 'rate', 'amount', 'gst_rate', 'cgst', 'sgst', 'igst'];
    protected $casts = ['qty' => 'float', 'rate' => 'float', 'amount' => 'float', 'gst_rate' => 'float', 'cgst' => 'float', 'sgst' => 'float', 'igst' => 'float'];
}
