<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'sender_type', 'body', 'is_internal'];
    protected $casts = ['is_internal' => 'boolean'];

    public function ticket(): BelongsTo      { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function user(): BelongsTo        { return $this->belongsTo(User::class); }
    public function attachments(): HasMany   { return $this->hasMany(SupportTicketAttachment::class, 'message_id'); }
}
