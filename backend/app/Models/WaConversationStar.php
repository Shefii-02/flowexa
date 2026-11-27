<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One user's star on one WA Cloud conversation (personal — see the wa_conversation_stars migration). */
class WaConversationStar extends Model
{
    public $timestamps = false;
    protected $fillable = ['company_id', 'user_id', 'conversation_id'];
}
