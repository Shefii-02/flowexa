<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccItem extends Model
{
    protected $table = 'acc_items';
    protected $fillable = [
        'company_id', 'name', 'sku', 'hsn', 'unit', 'gst_rate', 'sale_rate', 'purchase_rate', 'track_stock',
        'opening_qty', 'opening_value', 'reorder_level', 'is_active',
    ];
    protected $casts = [
        'gst_rate' => 'float', 'sale_rate' => 'float', 'purchase_rate' => 'float', 'opening_qty' => 'float', 'opening_value' => 'float',
        'reorder_level' => 'float', 'track_stock' => 'boolean', 'is_active' => 'boolean',
    ];
}
