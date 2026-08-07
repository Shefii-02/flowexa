<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'company_id',
        'direction',        // inbound | outbound
        'sender_type',      // customer | agent | system | bot
        'sent_by',          // user id, only set for outbound + sender_type=agent
        'wa_message_id',    // Meta's message id — used to match status webhook updates
        'type',             // text|image|video|document|audio|location|interactive|button|template
        'content',          // json — shape depends on `type`
        'status',           // queued|sent|delivered|read|failed
        'failure_reason',
        'status_updated_at',
    ];

    protected $casts = [
        'content'            => 'array',
        'status_updated_at'  => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // The staff member who sent this message, if it was an agent reply
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }
}
