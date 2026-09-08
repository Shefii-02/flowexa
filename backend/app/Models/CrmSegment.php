<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSegment extends Model
{
    protected $table = 'crm_segments';

    protected $fillable = ['company_id', 'created_by', 'name', 'description', 'filters', 'is_shared'];

    protected $casts = [
        'filters'   => 'array',
        'is_shared' => 'boolean',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
