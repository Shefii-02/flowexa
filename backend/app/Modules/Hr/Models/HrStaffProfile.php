<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrStaffProfile extends Model
{
    protected $table = 'hr_staff_profiles';

    protected $fillable = [
        'company_id', 'user_id', 'attendance_type', 'work_mode',
        'duty_start', 'duty_end', 'weekly_off',
        'monthly_salary', 'hourly_rate', 'monthly_target', 'is_active',
    ];

    protected $casts = [
        'weekly_off'     => 'array',
        'monthly_salary' => 'float',
        'hourly_rate'    => 'float',
        'monthly_target' => 'float',
        'is_active'      => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public static function forUser(int $companyId, int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            ['company_id' => $companyId],
        );
    }

    public function isGps(): bool
    {
        return $this->attendance_type === 'gps';
    }
}
