<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;

class WahaMessageLog extends Model
{
    protected $table = 'waha_message_logs';

    protected $fillable = [
        'company_id', 'job_id', 'campaign_name', 'session_id',
        'recipient_name', 'recipient_phone', 'recipient_type',
        'message_type', 'status', 'error_message', 'waha_message_id', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function job(): BelongsTo { return $this->belongsTo(MessageSenderJob::class, 'job_id'); }
}
