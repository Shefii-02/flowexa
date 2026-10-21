<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A personal sticky note — belongs to one user and is never visible to anyone else. */
class StickyNote extends Model
{
    protected $fillable = ['company_id', 'user_id', 'title', 'content', 'color', 'x', 'y', 'width', 'height', 'minimized', 'sort_order'];

    protected $casts = [
        'x' => 'integer', 'y' => 'integer', 'width' => 'integer', 'height' => 'integer',
        'minimized' => 'boolean', 'sort_order' => 'integer',
    ];
}
