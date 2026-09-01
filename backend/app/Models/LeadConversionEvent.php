<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadConversionEvent extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'phone', 'event_type',
        'from_value', 'to_value', 'trigger_message',
        'analysis_id', 'automated', 'notes',
    ];

    protected $casts = [
        'automated' => 'boolean',
    ];

    public function company(): BelongsTo   { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo   { return $this->belongsTo(Contact::class); }
    public function analysis(): BelongsTo  { return $this->belongsTo(ConversationAnalysis::class); }
}
