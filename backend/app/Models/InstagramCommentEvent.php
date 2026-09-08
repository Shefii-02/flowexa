<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramCommentEvent extends Model
{
    use HasFactory;

    protected $table = 'instagram_comment_events';
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo { return $this->belongsTo(InstagramAccount::class, 'instagram_account_id'); }
    public function automation(): BelongsTo { return $this->belongsTo(InstagramAutomation::class, 'instagram_automation_id'); }
}
