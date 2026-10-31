<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One conversion event reported to Meta for an Instagram account — also the business's own record of what was sent. */
class InstagramConversionEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['event_time' => 'datetime', 'value' => 'decimal:2'];
}
