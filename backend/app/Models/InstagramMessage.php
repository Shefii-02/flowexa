<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramMessage extends Model
{
    use HasFactory;

    protected $table = 'instagram_messages';
    protected $guarded = [];

    protected $casts = [
        'attachments' => 'array',
        'sent_at'     => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InstagramConversation::class, 'instagram_conversation_id');
    }
}
