<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetaAudienceTemplate extends Model
{
    use HasFactory;

    protected $table = 'meta_audience_templates';

    protected $guarded = [];

    protected $casts = [
        'interests'               => 'array',
        'behaviors'               => 'array',
        'targeting_json'          => 'array',
        'suggested_daily_budget'  => 'decimal:2',
        'is_active'               => 'boolean',
        'created_at'              => 'datetime',
        'updated_at'              => 'datetime',
    ];
}
