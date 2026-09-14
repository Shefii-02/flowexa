<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailIntegration extends Model
{
    protected $table = 'email_integrations';

    protected $fillable = [
        'company_id', 'connected_by', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
        'encryption', 'from_email', 'from_name', 'is_active', 'is_verified', 'last_verified_at', 'last_error',
    ];

    protected $hidden = ['smtp_password'];

    protected $casts = [
        'smtp_port'        => 'integer',
        'is_active'        => 'boolean',
        'is_verified'      => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function connectedBy(): BelongsTo { return $this->belongsTo(User::class, 'connected_by'); }
}
