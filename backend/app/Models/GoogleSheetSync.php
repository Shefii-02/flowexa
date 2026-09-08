<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleSheetSync extends Model
{
    use HasFactory;

    protected $table = 'google_sheet_syncs';
    protected $guarded = [];

    protected $casts = [
        'filters'         => 'array',
        'last_synced_id'  => 'integer',
        'last_row_count'  => 'integer',
        'interval_hours'  => 'integer',
        'last_synced_at'  => 'datetime',
        'is_active'       => 'boolean',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function integration(): BelongsTo { return $this->belongsTo(GoogleIntegration::class, 'google_integration_id'); }

    public function isDue(): bool
    {
        return $this->is_active
            && ($this->last_synced_at === null
                || $this->last_synced_at->addHours($this->interval_hours)->isPast());
    }
}
