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

    /**
     * Put every person's "open leads" counter back in line with the leads they really hold (assigned to them, not closed, not deleted).
     * The counter is bumped on assign and dropped on recall / transfer, but leads assigned by hand, imported, closed, deleted or recalled
     * without an assignment record never moved it — so it drifted (e.g. "101 / 100" on someone with no leads) and blocked auto-assign.
     * A person marked busy only because of that stale count is freed. One grouped query + one update per person whose number changed.
     */
    public static function recountWorkload(int $companyId): void
    {
        $real = DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('assigned_to')
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->selectRaw('assigned_to, count(*) c')->groupBy('assigned_to')->pluck('c', 'assigned_to');
        $max = User::where('company_id', $companyId)->pluck('max_leads', 'id');

        foreach (self::where('company_id', $companyId)->get() as $a) {
            $n = (int) ($real[$a->staff_id] ?? 0);
            $patch = [];
            if ((int) $a->current_leads_count !== $n) { $patch['current_leads_count'] = $n; }
            $cap = (int) ($max[$a->staff_id] ?? 0);
            if ($a->status === 'busy' && ($cap <= 0 || $n < $cap)) { $patch += ['status' => 'online', 'is_available' => true]; }
            if ($patch) { $a->update($patch); }
        }
    }

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
