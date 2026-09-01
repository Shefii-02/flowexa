<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAnalysis extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'phone', 'message_id', 'analyzed_at',
        'sentiment', 'sentiment_score', 'sentiment_reason',
        'detected_intent', 'intent_confidence', 'intent_details',
        'lead_score', 'lead_score_reason', 'buying_signals', 'objections',
        'recommended_actions', 'suggested_response',
        'escalate_to_human', 'escalation_reason',
        'context_snapshot', 'model_used', 'tokens_used', 'analysis_ms',
    ];

    protected $casts = [
        'analyzed_at'         => 'datetime',
        'sentiment_score'     => 'float',
        'intent_confidence'   => 'float',
        'lead_score'          => 'integer',
        'buying_signals'      => 'array',
        'objections'          => 'array',
        'recommended_actions' => 'array',
        'context_snapshot'    => 'array',
        'escalate_to_human'   => 'boolean',
        'tokens_used'         => 'integer',
        'analysis_ms'         => 'integer',
    ];

    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo  { return $this->belongsTo(Contact::class); }

    public function getScoreLabel(): string
    {
        return match(true) {
            $this->lead_score >= 91 => 'Convert Now',
            $this->lead_score >= 76 => 'Hot Lead',
            $this->lead_score >= 51 => 'Warm Lead',
            $this->lead_score >= 26 => 'Interested',
            default                 => 'Cold Lead',
        };
    }
}
