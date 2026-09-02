<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAvailability extends Model
{
    protected $table = 'staff_availability';

    protected $fillable = [
        'company_id', 'staff_id',
        'is_online', 'is_available', 'last_seen_at',
        'current_leads_count', 'today_leads_count', 'today_conversions', 'total_conversions',
        'avg_response_time_minutes', 'conversion_rate', 'performance_score', 'status',
    ];

    protected $casts = [
        'is_online'     => 'boolean',
        'is_available'  => 'boolean',
        'last_seen_at'  => 'datetime',
    ];

    public function staff(): BelongsTo   { return $this->belongsTo(User::class, 'staff_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function isAvailableForLead(): bool
    {
        return $this->is_available && $this->status !== 'busy' && $this->status !== 'offline';
    }

    public static function ensureExists(int $companyId, int $staffId): self
    {
        return self::firstOrCreate(
            ['staff_id' => $staffId],
            ['company_id' => $companyId, 'status' => 'offline']
        );
    }
}
