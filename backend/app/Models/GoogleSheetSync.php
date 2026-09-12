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
        'interval_minutes'=> 'integer',
        'last_synced_at'  => 'datetime',
        'is_active'       => 'boolean',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function integration(): BelongsTo { return $this->belongsTo(GoogleIntegration::class, 'google_integration_id'); }

    /** Minute-level cadence when set (e.g. 10 for "every 10 minutes"), else the hourly one. */
    public function cadenceMinutes(): int
    {
        return $this->interval_minutes ?: ($this->interval_hours * 60);
    }

    public function isDue(): bool
    {
        return $this->is_active
            && ($this->last_synced_at === null
                || $this->last_synced_at->addMinutes($this->cadenceMinutes())->isPast());
    }
}
