<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per uncaught exception the app reported, tagged with whichever
 * company/user was making the request — lets superadmin see "what bug did
 * this specific company hit" instead of grepping the shared laravel.log.
 */
class ErrorLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'actor_role', 'exception_class', 'message',
        'file', 'line', 'trace', 'method', 'url', 'status_code', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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
