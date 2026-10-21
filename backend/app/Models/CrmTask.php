<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTask extends Model
{
    protected $table = 'crm_tasks';

    public const TYPES = ['todo', 'call', 'email', 'whatsapp', 'meeting', 'followup'];

    protected $fillable = [
        'company_id', 'contact_id', 'deal_id', 'lead_id', 'synced_task_id', 'assigned_to', 'created_by',
        'title', 'description', 'type', 'priority', 'status', 'due_at', 'completed_at',
    ];

    protected $casts = [
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function deal(): BelongsTo { return $this->belongsTo(CrmDeal::class, 'deal_id'); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
