<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveRequest extends Model
{
    protected $table = 'hr_leave_requests';

    protected $fillable = [
        'company_id', 'user_id', 'leave_type_id',
        'start_date', 'end_date', 'days', 'half_day', 'reason',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'reviewed_at' => 'datetime',
        'half_day'    => 'boolean',
        'days'        => 'float',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(HrLeaveType::class, 'leave_type_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
