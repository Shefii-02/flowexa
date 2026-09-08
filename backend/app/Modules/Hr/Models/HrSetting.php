<?php

namespace App\Modules\Hr\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSetting extends Model
{
    protected $table = 'hr_settings';

    protected $fillable = [
        'company_id', 'office_lat', 'office_lng', 'geofence_radius_m',
        'office_start', 'office_end', 'early_window_minutes', 'grace_minutes',
        'require_late_note', 'require_early_leave_note', 'overtime_needs_approval',
        'overtime_multiplier', 'late_penalty_amount', 'payroll_working_days', 'deduct_unpaid_leave', 'deduct_absent_days', 'auto_availability', 'leave_auto_approve', 'timezone',
    ];

    protected $casts = [
        'office_lat'              => 'float',
        'office_lng'              => 'float',
        'geofence_radius_m'       => 'integer',
        'early_window_minutes'    => 'integer',
        'grace_minutes'           => 'integer',
        'require_late_note'       => 'boolean',
        'require_early_leave_note' => 'boolean',
        'overtime_needs_approval' => 'boolean',
        'overtime_multiplier'     => 'float',
        'late_penalty_amount'     => 'float',
        'payroll_working_days'    => 'integer',
        'deduct_unpaid_leave'     => 'boolean',
        'deduct_absent_days'      => 'boolean',
        'auto_availability'       => 'boolean',
        'leave_auto_approve'      => 'boolean',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId]);
    }

    public function hasGeofence(): bool
    {
        return $this->office_lat !== null && $this->office_lng !== null;
    }
}
