<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaMediaLibrary extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_media_library';

    protected $guarded = [];

    protected $casts = [
        'created_at'=>'datetime',
        'updated_at'=>'datetime',
        'deleted_at'=>'datetime',
    ];
}
