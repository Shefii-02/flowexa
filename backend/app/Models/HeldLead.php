<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A contact who would have become a lead while its source was switched off — kept until staff move it into Leads or discard it. */
class HeldLead extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_captured_at' => 'datetime', 'last_captured_at' => 'datetime', 'handled_at' => 'datetime',
        'hits' => 'integer',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function handler(): BelongsTo { return $this->belongsTo(User::class, 'handled_by'); }
}
