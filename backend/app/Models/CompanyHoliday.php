<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyHoliday extends Model
{
    protected $table = 'company_holidays';

    protected $fillable = ['company_id', 'date', 'name'];

    protected $casts = ['date' => 'date'];
}
