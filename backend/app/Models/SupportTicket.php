<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use SoftDeletes;

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    public const STATUSES   = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
    /** Hours allowed for the first support reply, by the priority the company picked. */
    public const SLA_HOURS  = ['urgent' => 4, 'high' => 8, 'medium' => 24, 'low' => 72];

    protected $fillable = [
        'ticket_no', 'company_id', 'created_by', 'department_id', 'subject', 'description', 'priority', 'status',
        'assigned_to', 'due_at', 'first_response_at', 'last_reply_at', 'last_reply_by', 'resolved_at', 'closed_at',
        'rating', 'rating_feedback',
    ];

    protected $casts = [
        'due_at' => 'datetime', 'first_response_at' => 'datetime', 'last_reply_at' => 'datetime',
        'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'rating' => 'integer',
    ];

    public function company(): BelongsTo    { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function department(): BelongsTo { return $this->belongsTo(SupportDepartment::class, 'department_id'); }
    public function messages(): HasMany     { return $this->hasMany(SupportTicketMessage::class, 'ticket_id'); }

    public static function dueAtFor(string $priority, ?\DateTimeInterface $from = null): \Carbon\Carbon
    {
        return \Carbon\Carbon::instance($from ?? now())->addHours(self::SLA_HOURS[$priority] ?? 24);
    }

    public function isOverdue(): bool
    {
        return $this->due_at && !$this->first_response_at && $this->due_at->isPast() && !in_array($this->status, ['resolved', 'closed'], true);
    }
}
