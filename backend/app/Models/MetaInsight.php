<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaInsight extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_insights';

    protected $guarded = [];

    protected $casts = [
        'created_at'=>'datetime',
        'updated_at'=>'datetime',
        'deleted_at'=>'datetime',
    ];
}
