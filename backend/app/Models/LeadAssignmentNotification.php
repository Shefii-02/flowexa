<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentNotification extends Model
{
    protected $fillable = [
        'company_id', 'assignment_id', 'staff_id',
        'notification_type', 'channel',
        'sent_at', 'read_at', 'responded_at',
        'response', 'response_delay_seconds',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'read_at'      => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function assignment(): BelongsTo { return $this->belongsTo(LeadAssignment::class, 'assignment_id'); }
    public function staff(): BelongsTo      { return $this->belongsTo(User::class, 'staff_id'); }
    public function company(): BelongsTo    { return $this->belongsTo(Company::class); }
}
