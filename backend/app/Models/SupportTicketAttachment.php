<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketAttachment extends Model
{
    protected $fillable = ['ticket_id', 'message_id', 'filename', 'path', 'mime', 'size'];

    public function ticket(): BelongsTo  { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function message(): BelongsTo { return $this->belongsTo(SupportTicketMessage::class, 'message_id'); }
}
