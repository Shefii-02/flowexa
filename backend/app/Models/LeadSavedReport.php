<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadSavedReport extends Model
{
    protected $table = 'lead_saved_reports';

    protected $fillable = [
        'company_id', 'created_by', 'name', 'filters', 'group_by', 'date_field', 'is_shared',
    ];

    protected $casts = [
        'filters'   => 'array',
        'is_shared' => 'boolean',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
