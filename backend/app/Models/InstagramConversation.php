<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramConversation extends Model
{
    use HasFactory;

    protected $table = 'instagram_conversations';
    protected $guarded = [];

    protected $casts = [
        'ai_enabled'       => 'boolean',
        'unread_count'     => 'integer',
        'collected'        => 'array',
        'last_message_at'  => 'datetime',
        'last_inbound_at'  => 'datetime',
        'lead_created_at'  => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function account(): BelongsTo { return $this->belongsTo(InstagramAccount::class, 'instagram_account_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function messages(): HasMany { return $this->hasMany(InstagramMessage::class)->orderBy('created_at'); }

    /**
     * Instagram allows a business to message a user for 24h after that user's last message
     * (outside it, only a tagged human-agent message goes through). Automations and the AI agent
     * check this before sending.
     */
    public function withinMessagingWindow(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subDay());
    }
}
