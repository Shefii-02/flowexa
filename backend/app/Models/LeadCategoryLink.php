<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One category a lead belongs to (a lead can have several; leads.category is the primary one). */
class LeadCategoryLink extends Model
{
    protected $fillable = ['company_id', 'lead_id', 'name'];

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
}
