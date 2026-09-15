<?php

namespace App\Modules\Hr\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSetting extends Model
{
    protected $table = 'hr_settings';

    protected $fillable = [
        'company_id', 'office_lat', 'office_lng', 'geofence_radius_m', 'geofence_mandatory',
        'office_start', 'office_end', 'early_window_minutes', 'grace_minutes',
        'clock_out_early_window_minutes', 'clock_out_grace_minutes',
        'require_late_note', 'require_early_leave_note', 'overtime_needs_approval',
        'overtime_multiplier', 'late_penalty_amount', 'payroll_working_days', 'deduct_unpaid_leave', 'deduct_absent_days', 'auto_availability', 'leave_auto_approve', 'timezone',
        'selfie_required_clock_in', 'selfie_required_clock_out',
        'selfie_required_break_start', 'selfie_required_break_end',
        'late_clockin_notify_user_ids', 'late_clockout_notify_user_ids',
        'break_overrun_notify_user_ids', 'leave_request_notify_user_ids',
    ];

    protected $casts = [
        'office_lat'              => 'float',
        'office_lng'              => 'float',
        'geofence_radius_m'       => 'integer',
        'geofence_mandatory'      => 'boolean',
        'early_window_minutes'    => 'integer',
        'grace_minutes'           => 'integer',
        'clock_out_early_window_minutes' => 'integer',
        'clock_out_grace_minutes'        => 'integer',
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
        'selfie_required_clock_in'    => 'boolean',
        'selfie_required_clock_out'   => 'boolean',
        'selfie_required_break_start' => 'boolean',
        'selfie_required_break_end'   => 'boolean',
        'late_clockin_notify_user_ids'   => 'array',
        'late_clockout_notify_user_ids'  => 'array',
        'break_overrun_notify_user_ids'  => 'array',
        'leave_request_notify_user_ids'  => 'array',
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
