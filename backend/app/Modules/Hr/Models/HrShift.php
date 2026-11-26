<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class HrShift extends Model
{
    protected $table = 'hr_shifts';

    protected $fillable = ['company_id', 'name', 'start_time', 'end_time', 'grace_minutes', 'color', 'is_active'];

    protected $casts = ['grace_minutes' => 'integer', 'is_active' => 'boolean'];

    /** HH:MM, whatever the DB driver returns (the time column comes back as HH:MM:SS). */
    public function getStartTimeAttribute($v): ?string { return $v ? substr((string) $v, 0, 5) : null; }
    public function getEndTimeAttribute($v): ?string { return $v ? substr((string) $v, 0, 5) : null; }
}
