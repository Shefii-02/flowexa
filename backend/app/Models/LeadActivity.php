<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call / message / meeting a staff member logged on a lead. */
class LeadActivity extends Model
{
    public const KINDS = ['call', 'message', 'meeting', 'visit', 'other'];
    /** Outcomes where the person was actually reached — the first of these moves a "New" lead to "Contacted". */
    public const REACHED = ['connected', 'interested', 'callback', 'not_interested'];
    public const OUTCOMES = ['connected', 'not_reachable', 'callback', 'interested', 'not_interested', 'wrong_number'];

    protected $fillable = [
        'company_id', 'lead_id', 'user_id', 'kind', 'outcome', 'is_first_contact',
        'duration_seconds', 'notes', 'next_follow_up_at', 'happened_at',
    ];

    protected $casts = [
        'is_first_contact'  => 'boolean',
        'duration_seconds'  => 'integer',
        'next_follow_up_at' => 'datetime',
        'happened_at'       => 'datetime',
    ];

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
