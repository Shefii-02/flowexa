<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The topic of one WA Chat conversation — see the conversation_topics migration. */
class ConversationTopic extends Model
{
    protected $guarded = [];

    protected $casts = ['confidence' => 'float', 'classified_at' => 'datetime'];

    public const TOPICS = [
        'lead_enquiry'      => 'Lead enquiry',
        'technical'         => 'Technical / support issue',
        'billing'           => 'Billing / payment',
        'complaint'         => 'Complaint',
        'existing_customer' => 'Existing customer',
        'other'             => 'Other',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
