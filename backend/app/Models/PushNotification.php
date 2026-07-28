<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushNotification extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'status',
        'sent_count',
        'error',
    ];

    protected $casts = [
        'data' => 'array',
        'sent_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
