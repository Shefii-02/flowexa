<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingCustomField extends Model
{
    protected $table = 'listing_custom_fields';

    protected $guarded = [];

    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
