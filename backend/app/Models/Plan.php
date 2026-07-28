<?php

// ════════════════════════════════════════════════════════════════════════════
// app/Models/Plan.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['name', 'messages_limit', 'price', 'features', 'is_active'];

    protected $casts = [
        'features'       => 'array',
        'is_active'      => 'boolean',
        'messages_limit' => 'integer',
        'price'          => 'decimal:2',
    ];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
