<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportDepartment extends Model
{
    protected $fillable = ['name', 'description', 'default_assignee_id', 'is_active', 'sort_order'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function defaultAssignee(): BelongsTo { return $this->belongsTo(User::class, 'default_assignee_id'); }
    public function tickets(): HasMany { return $this->hasMany(SupportTicket::class, 'department_id'); }
}
