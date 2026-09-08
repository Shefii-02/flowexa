<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaInsight extends Model
{
    use HasFactory;

    protected $table = 'meta_insights';

    protected $guarded = [];

    // NOTE: no SoftDeletes — the meta_insights table has no deleted_at column (rows are
    // upserted per object+date and pruned by date instead).

    protected $casts = [
        'date'           => 'date',
        'frequency'      => 'decimal:4',
        'ctr'            => 'decimal:4',
        'cpc'            => 'decimal:4',
        'cpm'            => 'decimal:4',
        'spend'          => 'decimal:4',
        'cost_per_lead'  => 'decimal:4',
        'purchase_value' => 'decimal:4',
        'roas'           => 'decimal:4',
        'raw_data'       => 'array',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    protected $hidden = ['raw_data'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
