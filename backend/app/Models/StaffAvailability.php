<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        return self::where('staff_id', $staffId)->first()
            ?? self::firstOrCreate(['staff_id' => $staffId], ['company_id' => $companyId, 'status' => self::defaultStatus($companyId)]);
    }

    /**
     * What a brand-new availability row starts as. Offline only when the company drives availability from HR attendance
     * (staff then come online by clocking in); for everyone else "offline" would mean nobody is ever available and
     * auto-assign would never find anyone, so staff start available and can mark themselves away.
     */
    public static function defaultStatus(int $companyId): string
    {
        try {
            if (Schema::hasTable('hr_settings') && Schema::hasTable('hr_attendance')
                && DB::table('hr_settings')->where('company_id', $companyId)->where('auto_availability', true)->exists()
                && DB::table('hr_attendance')->where('company_id', $companyId)->exists()) {
                return 'offline';
            }
        } catch (\Throwable) {
            // fall through to the available default
        }
        return 'online';
    }

    /** Effectively available right now (the same test auto-assign applies). */
    public function isEffectivelyAvailable(): bool
    {
        return $this->is_available && $this->status === 'online';
    }
}
