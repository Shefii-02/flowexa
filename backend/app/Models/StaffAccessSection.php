<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffAccessSection extends Model
{
    protected $fillable = ['company_id', 'user_id', 'section_key', 'is_main'];

    protected $casts = ['is_main' => 'boolean'];
}
