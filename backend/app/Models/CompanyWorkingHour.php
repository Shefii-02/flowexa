<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyWorkingHour extends Model
{
    protected $table = 'company_working_hours';

    protected $fillable = ['company_id', 'weekday', 'is_open', 'start_time', 'end_time'];

    protected $casts = [
        'weekday' => 'integer',
        'is_open' => 'boolean',
    ];

    /** Seed the 7 rows for a company from a single global start/end (from the assignment rule). */
    public static function ensureForCompany(int $companyId, array $openDays = [1, 2, 3, 4, 5], string $start = '09:00:00', string $end = '18:00:00'): void
    {
        for ($w = 0; $w <= 6; $w++) {
            static::firstOrCreate(
                ['company_id' => $companyId, 'weekday' => $w],
                ['is_open' => in_array($w, $openDays, true), 'start_time' => $start, 'end_time' => $end],
            );
        }
    }
}
