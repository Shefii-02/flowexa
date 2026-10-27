<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoRequest extends Model
{
    public const STATUSES = ['new', 'contacted', 'demo_scheduled', 'demo_done', 'won', 'lost'];

    protected $fillable = [
        'name', 'company_name', 'email', 'phone', 'business_type', 'team_size', 'preferred_date',
        'preferred_slot', 'message', 'status', 'assigned_to', 'scheduled_at', 'last_contacted_at',
        'notes', 'source', 'ip',
    ];

    protected $casts = [
        'preferred_date'    => 'date:Y-m-d',
        'scheduled_at'      => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
