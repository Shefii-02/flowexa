<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// ContactLabel
// ════════════════════════════════════════════════════════════════════════════
class ContactLabel extends Model
{
    protected $fillable = ['company_id', 'name', 'color'];

    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_label_pivot', 'contact_label_id', 'contact_id');
    }
}
