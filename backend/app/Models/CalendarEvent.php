<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    protected $table = 'calendar_events';

    public const TYPES  = ['appointment', 'followup', 'callback', 'meeting'];
    public const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

    protected $guarded = [];

    protected $casts = [
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function crmTask(): BelongsTo { return $this->belongsTo(CrmTask::class, 'crm_task_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function isSyncedToGoogle(): bool
    {
        return filled($this->google_event_id);
    }
}
